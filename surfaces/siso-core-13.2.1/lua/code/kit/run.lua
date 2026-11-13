--[[
kit.run — every suite in the tests directory, with totals (contract
kit-api). Generalised from jää 2.14.4 (sisoTests/run.lua).

  lua5.4 code/kit/run.lua [tests-dir]     (run from the project root;
                                           tests-dir defaults to sisoTests;
                                           further arguments are ignored)

  local R = require("kit.run")            -- the same file, as a module

Each suite runs in its own process, with the interpreter running this
command. A suite is any `*.test.lua` file under the tests directory, at
any depth, except under a fixtures directory: a path component named
`fixtures` or ending in `-fixtures` / `_fixtures` (`kit-fixtures/`,
`substrate/fixtures/`) is never entered. Suites run in byte-wise path
order; their order carries no meaning.

Two groups:
  gate   a per-gate test: a file that requires kit.helper and ends with
         the helper's done() (a call `<name>.done(` or `<name>:done(` in
         its code, comments and strings ignored). done() is what prints
         the digest line, so this is exactly the set of files with one.
         Each runs in two processes (the second with a padded
         environment, kit.digest) and its two "digest:" lines must agree;
         a gate test that fails that counts as a failed suite. The census
         (code/kit/census.lua) decides separately which gate each one
         tests.
  suite  every other file: contract tests, substrate-style suites
         (core.test.lua), the kit's own (kit-*.test.lua), scenarios. Run
         once.

A suite passes when it exits 0 and its last "<suite>: P/T passed" line
has P = T and no FAILED. Final lines:
  digests: N gate tests, A agree across processes
  run: S suites, P/T passed            (", F FAILED (files…)" otherwise)
Exit 0 only when every suite passes.
]]

local R = {}

-- A path component that names a fixtures directory.
function R.isFixtureDir(name)
  return name == "fixtures" or name:match("[-_]fixtures$") ~= nil
end

-- Whether a path relative to the tests directory is under a fixtures
-- directory.
function R.underFixtures(rel)
  local parts = {}
  for p in rel:gmatch("[^/]+") do parts[#parts + 1] = p end
  for i = 1, #parts - 1 do
    if R.isFixtureDir(parts[i]) then return true end
  end
  return false
end

local function shellQuote(s)
  return "'" .. s:gsub("'", "'\\''") .. "'"
end
R.shellQuote = shellQuote

-- Every suite file under dir, relative to it, sorted byte-wise.
function R.suiteFiles(dir)
  local p = io.popen("cd " .. shellQuote(dir) .. " 2>/dev/null && find . -name '*.test.lua' -type f 2>/dev/null"
    .. " | sed 's|^\\./||' | LC_ALL=C sort")
  local files = {}
  for line in p:lines() do
    if not R.underFixtures(line) then files[#files + 1] = line end
  end
  p:close()
  return files
end

local function readFile(path)
  local f = io.open(path, "rb")
  if not f then return nil end
  local s = f:read("a")
  f:close()
  return s
end

-- Whether a test file's source is a per-gate test (see the header).
function R.isGateTestSource(src)
  local L = require("kit.lint")
  local code, bare = L.strip(src)
  local usesHelper = code:match("require%s*%(?%s*[\"']kit%.helper[\"']") ~= nil
  local callsDone = bare:match("[%w_%]%)]%s*[%.:]%s*done%s*%(") ~= nil
  return usesHelper and callsDone
end

function R.isGateTest(path)
  local src = readFile(path)
  return src ~= nil and R.isGateTestSource(src)
end

-- The last result line of a suite's output, and its counts.
function R.result(out)
  local last = nil
  for line in out:gmatch("[^\n]+") do
    if line:match("^.-: %d+/%d+ passed") and not line:match("^shape:") then last = line end
  end
  if not last then return nil, 0, 0 end
  local p, t = last:match(": (%d+)/(%d+) passed")
  return last, tonumber(p), tonumber(t)
end

--[[
R.run(dir, lua, emit) → suites, passed, total, failedFiles
Runs every suite under dir and prints the report through emit.
]]
function R.run(dir, lua, emit)
  emit = emit or print
  local D = require("kit.digest")
  local base = dir:gsub("/+$", "") .. "/"
  local groups = { { name = "suite", files = {} }, { name = "gate", files = {} } }
  for _, f in ipairs(R.suiteFiles(base)) do
    local g = R.isGateTest(base .. f) and groups[2] or groups[1]
    g.files[#g.files + 1] = f
  end

  local suites, passed, total, failed = 0, 0, 0, {}
  local digestRuns, digestAgree = 0, 0
  for _, group in ipairs(groups) do
    local gp, gt = 0, 0
    for _, file in ipairs(group.files) do
      local command = shellQuote(lua) .. " " .. shellQuote(base .. file)
      local h = io.popen(command .. " 2>&1")
      local out = h:read("a")
      local ok = h:close()
      local digestProblem = nil
      if group.name == "gate" then
        local h2 = io.popen(D.second(command) .. " 2>&1")
        local out2 = h2:read("a")
        local ok2 = h2:close()
        digestRuns = digestRuns + 1
        local same, why = D.compare(out, out2)
        if same and not ok2 then same, why = false, "the second process failed" end
        if same then digestAgree = digestAgree + 1 else digestProblem = why end
      end
      local last, p, t = R.result(out)
      local good = ok and last ~= nil and p == t and not last:match("FAILED") and digestProblem == nil
      suites = suites + 1
      passed, total = passed + p, total + t
      gp, gt = gp + p, gt + t
      emit(string.format("  %-4s %-5s %-45s %s", good and "ok" or "FAIL", group.name, file,
        last or "(no result line)"))
      if not good then
        failed[#failed + 1] = file
        if not last then emit(out) end
        if digestProblem then emit("       digest: " .. digestProblem) end
      end
    end
    emit(string.format("  %s: %d suites, %d/%d passed", group.name, #group.files, gp, gt))
  end

  emit("")
  emit("digests: " .. digestRuns .. " gate tests, " .. digestAgree .. " agree across processes")
  if #failed == 0 then
    emit("run: " .. suites .. " suites, " .. passed .. "/" .. total .. " passed")
  else
    emit("run: " .. suites .. " suites, " .. passed .. "/" .. total .. " passed, "
      .. #failed .. " FAILED (" .. table.concat(failed, ", ") .. ")")
  end
  return suites, passed, total, failed
end

---------------------------------------------------------------- command

-- Run as a command when not loaded by require. require passes the module
-- name and the file it was found in; a command gets its arguments
-- instead, any number of them, so neither the count nor the second value
-- alone can tell (code/kit/census.lua decides the same way).
local modName, loadedFrom = ...
local isCommand = not (modName == "kit.run" and type(loadedFrom) == "string"
  and loadedFrom:match("run%.lua$") ~= nil)
if isCommand then
  package.path = "code/?.lua;sisoTests/?.lua;" .. package.path
  local lua = (arg and arg[-1]) or "lua5.4"
  local dir = (arg and arg[1]) or "sisoTests"
  if not os.execute("test -d " .. shellQuote(dir)) then
    print("run: no tests directory " .. dir)
    os.exit(1)
  end
  local _, _, _, failed = R.run(dir, lua)
  os.exit(#failed == 0 and 0 or 1)
end

return R
