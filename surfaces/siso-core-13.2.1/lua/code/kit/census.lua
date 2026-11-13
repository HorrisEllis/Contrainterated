--[[
kit.census — the gate census (contract kit-api; phased-spec
references/gate-tests.md, "Gate census"). Generalised from jää 2.14.4
(sisoTests/census.lua, sisoTests/tools/census.lua, its phased-spec §5.2),
pure gates only: no StateGates, no collection areas, no retries, no
implicit self-loops. In core 12 no gate may emit its own type (the
stream rejects it: SISO_SELF_LOOP), so a declared self-loop is a problem.

  lua5.4 code/kit/census.lua [registerModule] [testDir]
      (run from the project root; registerModule, when absent or "",
       defaults to KIT_REGISTER, then `register`, as kit.helper reads it;
       testDir defaults to sisoTests)

  local C = require("kit.census")   -- the same file, as a module

WHAT IT DOES
  1. Lists every gate from the register module: a list of gate module
     names, each returning a core Gate whose `shape` declares
     { emits = {…}, rejects = {…} } (lists of type strings). Modules are
     found on package.path and loaded as kit.helper loads them. A module
     that is missing, raises or is not a gate, a signature registered
     twice, a gate without a shape, and two signatures sharing one test
     file name are problems. A project with no register module (the
     default name `register`, not found) has no gates, and the census
     says so ("no register module 'register' found: no gates
     registered"); a register named by argument or KIT_REGISTER that is
     not found is a problem.
  2. Finds each gate's test: `<testDir>/<signature with every character
     outside [A-Za-z0-9_.-] replaced by '-'>.test.lua` (`app:parse` →
     `app-parse.test.lua`). Per-gate tests are recognised by exactly
     code/kit/run.lua's rule (R.suiteFiles, R.isGateTestSource), so the
     census and run cannot disagree on which files are gate tests. The
     file must be a per-gate test by that rule and must obtain its gate
     through kit.helper's gate("<signature>") (a call in its code;
     comments ignored). A per-gate test that names no registered gate
     (any other path) is an orphan. A per-gate test that overrides the
     register is refused: kit.helper reads the register named by
     KIT_REGISTER (the census sets it), unless the test calls
     use_register or plants modules through package.loaded,
     package.preload or package.searchers (mentioned anywhere in its
     code, strings included).
  3. Runs each such test as run.lua does (kit.digest: two processes,
     the second with a padded environment) with KIT_REGISTER set to the
     census's register, and reads the result line and the helper's
     "gates:", "shape:", "register:" and "digest:" lines. The test passes
     when both processes exit 0, the result has P = T and nothing FAILED,
     it obtained exactly its gate, it has at least one accepted case, its
     accepted cases share one output shape (distinct = 1), its two
     digests agree, and it printed at least one register line and every
     register line names the census's own register module resolved to the
     file the census read it from (both paths made absolute against the
     census's working directory, "." and ".." segments folded). This is
     the run-time half of the register check: it catches what the source
     check cannot see, such as another `register` put ahead on
     package.path or the test re-running itself under another
     KIT_REGISTER.
  4. Builds the type graph from the declared shapes: an edge from each
     gate to every gate that claims a type in its emits or rejects. A
     gate that declares its own type is a self-loop (a problem); any
     other cycle is a problem, named along its path.
  5. Lists the terminal types: the types in any emits or rejects, plus
     "error", that no gate claims.
  Prints the gates, problems, the graph summary and the terminal types,
  then the final line
    census: G gates, T tested, U untested
  G gates loaded, T with a passing test, U with no test file. Exit 0 only
  when every registered gate has a passing per-gate test and there is no
  other problem.
]]

-- Run as a command when not loaded by require. require passes the module
-- name and the file it was found in; a command gets its arguments
-- instead (two of them here, so the second alone cannot tell). The
-- command finds the kit from the project root.
local modName, loadedFrom = ...
local isCommand = not (modName == "kit.census" and type(loadedFrom) == "string"
  and loadedFrom:match("census%.lua$") ~= nil)
if isCommand then
  package.path = "code/?.lua;sisoTests/?.lua;" .. package.path
end

local R = require("kit.run")
local L = require("kit.lint")
local D = require("kit.digest")

local C = {}

local shellQuote = R.shellQuote

local function sortedKeys(s)
  local ks = {}
  for k in pairs(s) do ks[#ks + 1] = k end
  table.sort(ks)
  return ks
end

local function readFile(path)
  local f = io.open(path, "rb")
  if not f then return nil end
  local s = f:read("a")
  f:close()
  return s
end

local function escapePattern(s)
  return (s:gsub("[%^%$%(%)%%%.%[%]%*%+%-%?]", "%%%0"))
end

local function typeList(v)
  if type(v) ~= "table" then return false end
  local n, count = #v, 0
  for _ in pairs(v) do count = count + 1 end
  if count ~= n then return false end
  for _, x in ipairs(v) do if type(x) ~= "string" then return false end end
  return true
end

-- The test file name of a gate signature.
function C.testFile(signature)
  return (tostring(signature):gsub("[^%w_%.%-]", "-")) .. ".test.lua"
end

-- The register module to read: the argument, then KIT_REGISTER, then
-- `register` (kit.helper's rule). The second value is true when it is the
-- default name.
function C.registerName(argName)
  if argName and argName ~= "" then return argName, false end
  local env = os.getenv("KIT_REGISTER")
  if env and env ~= "" then return env, false end
  return "register", true
end

-- Load a module by name as kit.helper does (package.path, once, kept in
-- package.loaded). → ok, value | false, why
local function loadModule(name)
  local have = package.loaded[name]
  if have ~= nil then return true, have end
  local path, why = package.searchpath(name, package.path)
  if not path then return false, "not found" .. why end
  local chunk, err = loadfile(path)
  if not chunk then return false, tostring(err) end
  local ok, value = pcall(chunk, name, path)
  if not ok then return false, "raised: " .. tostring(value) end
  if value == nil then value = true end
  package.loaded[name] = value
  return true, value
end

--[[
C.loadGates(registerModule) → gates, problems, found, path
gates: { signature, module, gate } sorted by signature; problems: strings;
found: false when the register module itself is not on package.path;
path: the file the register module resolves to on package.path (nil when
it is not found there).
]]
function C.loadGates(registerModule)
  local problems, gates, bySig, byFile = {}, {}, {}, {}
  local path = package.searchpath(registerModule, package.path)
  if package.loaded[registerModule] == nil and not path then
    return {}, {}, false
  end
  local ok, list = loadModule(registerModule)
  if not ok then
    return {}, { "cannot load register module '" .. registerModule .. "': " .. list }, true, path
  end
  if not typeList(list) then
    return {}, { "register module '" .. registerModule .. "' does not return a list of gate module names" }, true, path
  end
  for _, name in ipairs(list) do
    local okG, g = loadModule(name)
    if not okG then
      problems[#problems + 1] = "cannot load gate module '" .. name .. "': " .. g
    elseif type(g) ~= "table" or type(g.signature) ~= "string" or type(g.transform) ~= "function" then
      problems[#problems + 1] = "module '" .. name .. "' in " .. registerModule
        .. " is not a gate (no string signature and transform function)"
    elseif bySig[g.signature] then
      problems[#problems + 1] = "signature '" .. g.signature .. "' registered twice ("
        .. bySig[g.signature].module .. ", " .. name .. ")"
    else
      local entry = { signature = g.signature, module = name, gate = g }
      local shape = g.shape
      if type(shape) ~= "table" or not typeList(shape.emits) or not typeList(shape.rejects) then
        problems[#problems + 1] = "gate '" .. g.signature .. "' declares no shape { emits = {…}, rejects = {…} }"
      end
      local file = C.testFile(g.signature)
      if byFile[file] then
        problems[#problems + 1] = "gates '" .. byFile[file] .. "' and '" .. g.signature
          .. "' share one test file name " .. file
      end
      byFile[file] = g.signature
      bySig[g.signature] = entry
      gates[#gates + 1] = entry
    end
  end
  table.sort(gates, function(a, b) return a.signature < b.signature end)
  return gates, problems, true, path
end

--[[
C.graph(gates) → { nodes, edges = { {from, to} … }, cycles = { "a → b → a" … },
                   selfLoops = { sig … }, terminal = { type … }, problems }
`gates` are entries { signature, gate }. A shape that is not a table of
lists contributes nothing (loadGates reports it).
]]
function C.graph(gates)
  local claimed, adj, outTypes, problems = {}, {}, { error = true }, {}
  local edges, selfLoops = {}, {}
  for _, e in ipairs(gates) do claimed[e.signature] = true end
  for _, e in ipairs(gates) do
    local sig, shape = e.signature, e.gate.shape
    local outs = {}
    if type(shape) == "table" then
      for _, field in ipairs({ "emits", "rejects" }) do
        if typeList(shape[field]) then
          for _, t in ipairs(shape[field]) do outs[t] = true end
        end
      end
    end
    adj[sig] = {}
    for _, t in ipairs(sortedKeys(outs)) do
      outTypes[t] = true
      if t == sig then
        selfLoops[#selfLoops + 1] = sig
        problems[#problems + 1] = "gate '" .. sig .. "' declares a self-loop: its shape names its own type"
          .. " (the stream rejects a gate that emits its own type: SISO_SELF_LOOP)"
      elseif claimed[t] then
        adj[sig][#adj[sig] + 1] = t
        edges[#edges + 1] = { from = sig, to = t }
      end
    end
  end

  -- Cycles (self-loops excluded above), in a fixed order: each back edge
  -- found by a depth-first walk from the sorted signatures names one.
  local cycles, colour, stack = {}, {}, {}
  local function visit(n)
    colour[n] = "grey"
    stack[#stack + 1] = n
    for _, m in ipairs(adj[n]) do
      if colour[m] == "grey" then
        local from = 1
        for i = #stack, 1, -1 do if stack[i] == m then from = i break end end
        local path = {}
        for i = from, #stack do path[#path + 1] = stack[i] end
        path[#path + 1] = m
        cycles[#cycles + 1] = table.concat(path, " → ")
      elseif colour[m] == nil then
        visit(m)
      end
    end
    stack[#stack] = nil
    colour[n] = "black"
  end
  for _, sig in ipairs(sortedKeys(claimed)) do
    if colour[sig] == nil then visit(sig) end
  end

  local terminal = {}
  for _, t in ipairs(sortedKeys(outTypes)) do
    if not claimed[t] then terminal[#terminal + 1] = t end
  end
  table.sort(selfLoops)
  return { nodes = #gates, edges = edges, cycles = cycles, selfLoops = selfLoops,
    terminal = terminal, problems = problems }
end

-- What a test's source does to override the register, or nil.
local OVERRIDES = { "use_register", "package.loaded", "package.preload", "package.searchers" }
function C.overridesRegister(src)
  local code = L.strip(src)
  local flat = code:gsub("%s*%.%s*", ".")
  for _, word in ipairs(OVERRIDES) do
    if flat:find(word, 1, true) then return word end
  end
  return nil
end

-- Whether a test's code obtains `signature` through the helper's gate().
function C.obtainsGate(src, signature)
  local code = L.strip(src)
  local q = escapePattern(signature)
  return code:match("[%w_%]%)]%s*%.%s*gate%s*%(%s*\"" .. q .. "\"%s*%)") ~= nil
    or code:match("[%w_%]%)]%s*%.%s*gate%s*%(%s*'" .. q .. "'%s*%)") ~= nil
end

--[[
C.checkTests(gates, dir) → tested, untested, problems
dir ends in "/". tested: { entry, file } for each gate whose test file is a
per-gate test that obtains its gate through the helper and does not
override the register; untested: signatures with no test file.
]]
function C.checkTests(gates, dir)
  local tested, untested, problems, expected = {}, {}, {}, {}
  for _, e in ipairs(gates) do
    local name = C.testFile(e.signature)
    expected[name] = true
    local src = readFile(dir .. name)
    if not src then
      untested[#untested + 1] = e.signature
    elseif not R.isGateTestSource(src) then
      problems[#problems + 1] = name .. " is not a per-gate test (run.lua's rule: it requires kit.helper"
        .. " and ends with done()); it does not obtain its gate through kit.helper"
    elseif not C.obtainsGate(src, e.signature) then
      problems[#problems + 1] = name .. " does not obtain its gate through kit.helper's gate('"
        .. e.signature .. "')"
    else
      local how = C.overridesRegister(src)
      if how then
        problems[#problems + 1] = name .. " overrides the register (" .. how
          .. "): a per-gate test reads the project's register"
      else
        tested[#tested + 1] = { entry = e, file = name }
      end
    end
  end
  for _, f in ipairs(R.suiteFiles(dir)) do
    if not expected[f] and R.isGateTest(dir .. f) then
      problems[#problems + 1] = "orphan test file " .. f .. " names no registered gate"
    end
  end
  return tested, untested, problems
end

-- The result line and the helper's gates, shape, register and digest
-- lines. r.registers lists every register line, in order.
function C.parseRun(output)
  local r = { registers = {} }
  for line in output:gmatch("[^\n]+") do
    local g = line:match("^gates: (.*)$")
    local d = line:match("^digest: (%x+)$")
    local a, n, rj, k = line:match("^shape: accepted=(%d+) distinct=(%d+) rejections=(%d+) keys=(.*)$")
    local rm, rp = line:match("^register: (%S+) (.+)$")
    if g then r.gates = g
    elseif rm then r.registers[#r.registers + 1] = { module = rm, path = rp }
    elseif d then r.digest = d
    elseif a then
      r.accepted, r.distinct, r.rejections, r.keys = tonumber(a), tonumber(n), tonumber(rj), k
    else
      local suite, p, t = line:match("^(.-): (%d+)/(%d+) passed")
      if suite then
        r.suite, r.passed, r.total = suite, tonumber(p), tonumber(t)
        r.failedCount = tonumber(line:match(", (%d+) FAILED")) or 0
      end
    end
  end
  return r
end

--[[
C.checkRun(signature, r, exitOk, digestOk, digestWhy) → problems
r is C.parseRun of the first process's output; exitOk is true when both
processes exited 0; digestOk/digestWhy are kit.digest's verdict (nil when
not checked).
]]
function C.checkRun(signature, r, exitOk, digestOk, digestWhy)
  local problems = {}
  if not r.suite then
    problems[#problems + 1] = "no result line"
  elseif not exitOk or r.failedCount > 0 or r.passed ~= r.total then
    problems[#problems + 1] = "tests fail (" .. tostring(r.passed) .. "/" .. tostring(r.total) .. ")"
  end
  if r.gates ~= signature then
    problems[#problems + 1] = "obtained gates '" .. tostring(r.gates) .. "', expected exactly '" .. signature .. "'"
  end
  if r.digest == nil then
    problems[#problems + 1] = "no digest line (the test did not end with helper.done())"
  elseif digestOk == false and exitOk then
    problems[#problems + 1] = "digest: " .. tostring(digestWhy)
  end
  if r.accepted == nil then
    problems[#problems + 1] = "no shape line (the test did not end with helper.done())"
  else
    if r.accepted < 1 then problems[#problems + 1] = "no accepted case" end
    if r.distinct > 1 then
      problems[#problems + 1] = "accepted cases have " .. r.distinct .. " output shapes (" .. r.keys
        .. "): the gate has more than one effect — split it"
    end
  end
  return problems
end

-- A path made absolute against cwd (when relative and cwd is known), with
-- empty and "." segments dropped and ".." folded. Symbolic links are not
-- resolved.
function C.normalPath(path, cwd)
  local p = tostring(path)
  local absolute = p:sub(1, 1) == "/"
  if not absolute and cwd and cwd ~= "" then
    p = cwd .. "/" .. p
    absolute = cwd:sub(1, 1) == "/"
  end
  local parts = {}
  for seg in p:gmatch("[^/]+") do
    if seg == ".." and #parts > 0 and parts[#parts] ~= ".." then
      parts[#parts] = nil
    elseif seg ~= "." then
      parts[#parts + 1] = seg
    end
  end
  return (absolute and "/" or "") .. table.concat(parts, "/")
end

-- The census's working directory, or nil.
function C.cwd()
  local h = io.popen("pwd 2>/dev/null")
  if not h then return nil end
  local d = h:read("l")
  h:close()
  return d
end

--[[
C.checkRegister(r, module, path, cwd) → problems
r is C.parseRun of a test's output; module and path are the register the
census read and the file it resolved to (path nil when it did not resolve
to a file); cwd is the directory both relative paths are taken from.
]]
function C.checkRegister(r, module, path, cwd)
  local problems = {}
  local regs = r.registers or {}
  if #regs == 0 then
    problems[1] = "no register line (kit.helper's done() prints one): the census cannot tell which register it read"
    return problems
  end
  local want = path and C.normalPath(path, cwd)
  for _, reg in ipairs(regs) do
    if reg.module ~= module then
      problems[#problems + 1] = "read register '" .. reg.module .. "', not the project's register '" .. module .. "'"
    elseif reg.path == "(none)" then
      problems[#problems + 1] = "register '" .. module .. "' was not loaded from a file by kit.helper"
        .. " (it was already in package.loaded), not from " .. tostring(path)
    elseif want == nil or C.normalPath(reg.path, cwd) ~= want then
      problems[#problems + 1] = "register '" .. module .. "' resolved to " .. reg.path .. ", not "
        .. tostring(path) .. " (the file the census read)"
    end
  end
  return problems
end

local function names(t) return #t == 0 and "none" or table.concat(t, ", ") end

--[[
C.run(registerModule, dir, lua, emit, isDefault) → ok, gates, tested, untested
Runs the whole census and prints the report through emit.
]]
function C.run(registerModule, dir, lua, emit, isDefault)
  emit = emit or print
  local base = dir:gsub("/+$", "") .. "/"
  local problems = {}

  local gates, loadProblems, found, registerPath = C.loadGates(registerModule)
  for _, s in ipairs(loadProblems) do problems[#problems + 1] = s end
  if not found then
    if isDefault then
      emit("no register module '" .. registerModule .. "' found: no gates registered")
    else
      problems[#problems + 1] = "register module '" .. registerModule .. "' not found on package.path"
    end
  end
  if not os.execute("test -d " .. shellQuote(base)) then
    problems[#problems + 1] = "no tests directory " .. dir
  end

  local tested, untested, testProblems = C.checkTests(gates, base)
  for _, s in ipairs(testProblems) do problems[#problems + 1] = s end

  local passing = 0
  local cwd = #tested > 0 and C.cwd() or nil
  for _, t in ipairs(tested) do
    local cmd = "KIT_REGISTER=" .. shellQuote(registerModule) .. " " .. shellQuote(lua) .. " "
      .. shellQuote(base .. t.file)
    local digestOk, digestWhy, out, exitOk = D.runTwice(cmd)
    local r = C.parseRun(out)
    local runProblems = C.checkRun(t.entry.signature, r, exitOk, digestOk, digestWhy)
    for _, s in ipairs(C.checkRegister(r, registerModule, registerPath, cwd)) do
      runProblems[#runProblems + 1] = s
    end
    if #runProblems == 0 then
      passing = passing + 1
      emit(string.format("  ok    %-40s %d/%d  accepted=%d rejections=%d shape=%s",
        t.entry.signature, r.passed, r.total, r.accepted, r.rejections, r.keys))
    else
      emit(string.format("  FAIL  %-40s %s", t.entry.signature, t.file))
      for _, s in ipairs(runProblems) do problems[#problems + 1] = t.file .. ": " .. s end
    end
  end

  local g = C.graph(gates)
  for _, s in ipairs(g.problems) do problems[#problems + 1] = s end
  for _, c in ipairs(g.cycles) do problems[#problems + 1] = "cycle in the type graph: " .. c end

  for _, s in ipairs(untested) do
    emit("UNTESTED " .. s .. " (expected " .. base .. C.testFile(s) .. ")")
  end
  for _, s in ipairs(problems) do emit("PROBLEM  " .. s) end
  emit("")
  emit("type graph: " .. g.nodes .. " gates, " .. #g.edges .. " edges, "
    .. (#g.cycles == 0 and "acyclic" or (#g.cycles .. " cycles")) .. ", "
    .. #g.selfLoops .. " self-loops")
  emit("terminal types: " .. names(g.terminal))
  emit("census: " .. #gates .. " gates, " .. passing .. " tested, " .. #untested .. " untested")
  local ok = #untested == 0 and #problems == 0 and passing == #gates
  return ok, #gates, passing, #untested
end

---------------------------------------------------------------- command

if isCommand then
  local lua = (arg and arg[-1]) or "lua5.4"
  local registerModule, isDefault = C.registerName(arg and arg[1])
  local dir = (arg and arg[2]) or "sisoTests"
  local ok = C.run(registerModule, dir, lua, print, isDefault)
  os.exit(ok and 0 or 1)
end

return C
