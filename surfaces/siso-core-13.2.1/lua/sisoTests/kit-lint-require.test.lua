-- kit-lint-require — code/kit/lint.lua flags `require` used as a value
-- (fixtures in sisoTests/kit-fixtures/lint-require/, linted with the lint
-- fixtures' config), and decides "run as a command" from how it was
-- loaded, not from how many arguments it was given.
--   lua5.4 sisoTests/kit-lint-require.test.lua
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local test, assert, assertEqual = H.test, H.assert, H.assertEqual
local L = require("kit.lint")

local fx = root .. "sisoTests/kit-fixtures/lint-require/"
local CFG = dofile(root .. "sisoTests/kit-fixtures/lint/lint_config.lua")
local VALUE = "require used as a value"

local function lint(fixture, logical)
  return L.lintFile(CFG, logical or ("code/gates/" .. fixture), fx .. fixture)
end

-- "RULE:name@line(detail)" for every finding, sorted
local function findings(fs)
  local out = {}
  for _, f in ipairs(fs) do
    out[#out + 1] = f.rule .. ":" .. tostring(f.name) .. "@" .. tostring(f.line)
      .. (f.detail and ("(" .. f.detail .. ")") or "")
  end
  table.sort(out)
  return table.concat(out, ",")
end

local function value(line) return "DYNAMIC_REQUIRE:@" .. line .. "(" .. VALUE .. ")" end

---------------------------------------------------------------- the rule

test("pcall(require, name): require as a value", function()
  assertEqual(findings(lint("pcall.lua")), value(2))
end)

test("an aliased require: local load = require", function()
  assertEqual(findings(lint("alias.lua")), value(2))
end)

test("require passed to a function, inside a function body", function()
  assertEqual(findings(lint("passed.lua")), value(4))
end)

test("plain requires stay clean: direct, inside an argument, as a tail call", function()
  assertEqual(findings(lint("plain.lua")), "")
end)

test("a call inside require's argument: dynamic, but not a value", function()
  assertEqual(findings(lint("nested.lua")), "DYNAMIC_REQUIRE:@4")
end)

test("every layer, not only the pure ones", function()
  assertEqual(findings(lint("pcall.lua", "code/runner.lua")), value(2), "pcall")
  assertEqual(findings(lint("alias.lua", "code/runner.lua")), value(2), "alias")
  assertEqual(findings(lint("passed.lua", "code/runner.lua")), value(4), "passed")
  assertEqual(findings(lint("plain.lua", "code/runner.lua")), "", "plain")
end)

test("the lint's own source is clean under the rule", function()
  local cfg = { source = "code/", layers = { kit = { path = "code/kit/", requires = { "kit.*" } } } }
  assertEqual(findings(L.lintFile(cfg, "code/kit/lint.lua", root .. "code/kit/lint.lua")), "")
end)

---------------------------------------------------------------- the command

local function sh(s) return "'" .. s:gsub("'", "'\\''") .. "'" end

local function absolute(p)
  if p:sub(1, 1) == "/" then return p end
  local h = io.popen("pwd")
  local cwd = h:read("l")
  h:close()
  return cwd .. "/" .. p
end

local function capture(cmd)
  local h = io.popen(cmd .. " 2>&1; echo \"exit=$?\"")
  local out = h:read("a")
  h:close()
  local lines = {}
  for l in out:gmatch("[^\n]*") do lines[#lines + 1] = l end
  while lines[#lines] == "" do lines[#lines] = nil end
  return lines, out
end

local lintPath = absolute(root .. "code/kit/lint.lua")
local project = root .. "sisoTests/kit-fixtures/lint/project"

for _, case in ipairs({
  { "zero arguments", "" },
  { "one argument", " a" },
  { "two arguments shaped like require's (a module name, a lint.lua path)", " kit.lint code/kit/lint.lua" },
  { "several arguments", " a b c d" },
}) do
  test("command with " .. case[1] .. ": lints and exits as a command", function()
    local lines, out = capture("cd " .. sh(project) .. " && LUA_PATH='../?.lua;;' lua5.4 "
      .. sh(lintPath) .. case[2])
    assertEqual(lines[#lines], "exit=1", out)
    assertEqual(lines[#lines - 1], "lint: 3 files, 1 findings, 1 excused, 1 stale exceptions", out)
  end)
end

for _, case in ipairs({ { "zero", "", 0 }, { "one", " a", 1 }, { "several", " a b c", 3 } }) do
  test("require(\"kit.lint\") from a script given " .. case[1] .. " arguments: the module, not a run", function()
    local lines, out = capture("lua5.4 " .. sh(fx .. "loader.lua") .. case[2])
    assertEqual(#lines, 2, out)
    assertEqual(lines[1], "module table function args=" .. case[3], out)
    assertEqual(lines[2], "exit=0", out)
  end)
end

test("require(\"kit.lint\") in this process returned the module", function()
  assert(type(L) == "table" and type(L.lintFile) == "function" and type(L.run) == "function", "module")
end)

H.finish("kit-lint-require")
