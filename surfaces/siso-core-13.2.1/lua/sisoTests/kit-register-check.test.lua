-- kit-register-check — kit.helper's register line, the census's run-time
-- register check, and run.lua's command/module decision. Uses the fixture
-- project under sisoTests/kit-fixtures/register-check/project/ (fx:
-- signatures, not project gates; module `register`, the default name), run
-- from its own directory with the kit on LUA_PATH, and the kit's helper
-- fixture register. Expected lines are worked by hand from the fixtures.
--   lua5.4 sisoTests/kit-register-check.test.lua      (from the project root)
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local test, assert, assertEqual = H.test, H.assert, H.assertEqual
local C = require("kit.census")
local R = require("kit.run")

local lua = (arg and arg[-1]) or "lua5.4"
local q = R.shellQuote

local function sh(cmd)
  local h = io.popen(cmd .. " 2>&1")
  local out = h:read("a")
  local ok = h:close()
  return out, ok and true or false
end

local function lines(out)
  local t = {}
  for line in out:gmatch("[^\n]+") do t[#t + 1] = line end
  return t
end

local function lastLine(out)
  local t = lines(out)
  return t[#t]
end

local function has(list, needle)
  for _, s in ipairs(list) do if s:find(needle, 1, true) then return true end end
  return false
end

local function joined(list) return table.concat(list, " | ") end

-- The project root as an absolute path ending in "/".
local ABS = root
if ABS:sub(1, 1) ~= "/" then
  local h = io.popen("pwd")
  ABS = h:read("l") .. "/" .. root
  h:close()
end
local PROJECT = ABS .. "sisoTests/kit-fixtures/register-check/project"
local KIT_PATH = ABS .. "code/?.lua;" .. ABS .. "sisoTests/?.lua;;"
local CENSUS = ABS .. "code/kit/census.lua"
local RUN = ABS .. "code/kit/run.lua"

-- A shell command run from the fixture project, with the kit on LUA_PATH
-- and no KIT_REGISTER of the caller's.
local function inProject(cmd)
  return sh("cd " .. q(PROJECT) .. " && unset KIT_REGISTER; LUA_PATH_5_4=" .. q(KIT_PATH)
    .. " LUA_PATH=" .. q(KIT_PATH) .. " " .. cmd)
end

-- ── kit.helper: the register line ─────────────

test("helper: done() prints the register `register` and the file it resolved to, between shape and digest", function()
  local out, ok = inProject(q(lua) .. " sisoTests/fx-echo.test.lua")
  assert(ok, out)
  local t = lines(out)
  assertEqual(t[1], "gates: fx:echo")
  assertEqual(t[2], "shape: accepted=2 distinct=1 rejections=1 keys=fx:echoed")
  assertEqual(t[3], "register: register code/register.lua")
  assert(t[4]:match("^digest: %x+$"), t[4])
  assertEqual(t[#t], "fx-echo: 3/3 passed")
end)

test("helper: the register line names the module KIT_REGISTER gives and its file", function()
  local out, ok = inProject("KIT_REGISTER=register_other " .. q(lua) .. " sisoTests/fx-echo.test.lua")
  assert(ok, out)
  assertEqual(lines(out)[3], "register: register_other code/register_other.lua")
end)

test("helper: another `register` ahead on package.path is the file named", function()
  local out, ok = inProject("KIT_REGISTER=register " .. q(lua) .. " shadow/fx-echo.test.lua")
  assert(ok, "the shadowing test passes on its own: " .. out)
  assertEqual(lines(out)[3], "register: register shadow/lib/register.lua")
end)

test("helper: a register the helper did not load from a file is named with (none)", function()
  local probe = "local h = require('kit.helper'); h.done('probe')"
  local out = inProject(q(lua) .. " -e " .. q(probe))
  assertEqual(lines(out)[3], "register: register (none)")
  local planted = "package.path = 'code/?.lua;' .. package.path; package.loaded.register = { 'gates.fx_echo' };"
    .. " local h = require('kit.helper');"
    .. " h.gate('fx:echo'); h.done('probe')"
  local out2 = inProject(q(lua) .. " -e " .. q(planted))
  assertEqual(lines(out2)[1], "gates: fx:echo")
  assertEqual(lines(out2)[3], "register: register (none)")
end)

test("helper: summary and register_path follow use_register", function()
  local helper = require("kit.helper")
  helper.use_register("kit-fixtures.helper.register")
  assertEqual(helper.register_path(), nil, "not read yet")
  helper.gate("fx:double")
  assertEqual(helper.register_path(), root .. "sisoTests/kit-fixtures/helper/register.lua")
  local s = helper.summary()
  assertEqual(s.register, "kit-fixtures.helper.register")
  assertEqual(s.registerPath, root .. "sisoTests/kit-fixtures/helper/register.lua")
end)

-- ── census: reading and checking register lines ─

test("parseRun: every register line, in order", function()
  local r = C.parseRun("gates: x\nregister: reg a/b c.lua\nregister: other d.lua\nx: 1/1 passed\n")
  assertEqual(#r.registers, 2)
  assertEqual(r.registers[1].module .. "|" .. r.registers[1].path, "reg|a/b c.lua")
  assertEqual(r.registers[2].module .. "|" .. r.registers[2].path, "other|d.lua")
  assertEqual(r.suite, "x")
  assertEqual(#C.parseRun("x: 1/1 passed\n").registers, 0)
end)

test("normalPath: absolute against cwd, '.', '//' and '..' folded", function()
  assertEqual(C.normalPath("code/register.lua", "/p"), "/p/code/register.lua")
  assertEqual(C.normalPath("./code//x/../register.lua", "/p/"), "/p/code/register.lua")
  assertEqual(C.normalPath("/q/./r.lua", "/p"), "/q/r.lua")
  assertEqual(C.normalPath("a/../b.lua", nil), "b.lua")
end)

test("checkRegister: the census's register and file pass, however the path is spelt", function()
  local r = C.parseRun("register: register ./code/register.lua\n")
  assertEqual(#C.checkRegister(r, "register", "code/register.lua", "/p"), 0)
  local r2 = C.parseRun("register: register /p/code/register.lua\n")
  assertEqual(#C.checkRegister(r2, "register", "code/register.lua", "/p"), 0)
end)

test("checkRegister: no line, another module, another file, (none), one wrong line among several", function()
  local function p(out, path) return C.checkRegister(C.parseRun(out), "register", path or "code/register.lua", "/p") end
  assert(has(p("x: 1/1 passed\n"), "no register line"), "no line")
  assert(has(p("register: register_other code/register_other.lua\n"),
    "read register 'register_other', not the project's register 'register'"), "module")
  assert(has(p("register: register shadow/lib/register.lua\n"),
    "resolved to shadow/lib/register.lua, not code/register.lua"), "file")
  assert(has(p("register: register (none)\n"), "was not loaded from a file"), "(none)")
  local mixed = p("register: register code/register.lua\nregister: register_other code/register_other.lua\n")
  assertEqual(#mixed, 1, joined(mixed))
  local nofile = C.checkRegister(C.parseRun("register: register code/register.lua\n"), "register", nil, "/p")
  assert(has(nofile, "not nil"), joined(nofile))
end)

test("loadGates: also gives the file the register resolved to", function()
  local _, _, found, path = C.loadGates("kit-fixtures.census.register_clean")
  assert(found, "found")
  assertEqual(path, root .. "sisoTests/kit-fixtures/census/register_clean.lua")
end)

-- ── census: end to end on the fixture project ──

test("census: the clean fixture project passes (no arguments: `register`, sisoTests)", function()
  local out, ok = inProject(q(lua) .. " " .. q(CENSUS))
  assert(ok, out)
  assert(out:find("  ok    fx:echo", 1, true), out)
  assert(not out:find("PROBLEM", 1, true), out)
  assertEqual(lastLine(out), "census: 1 gates, 1 tested, 0 untested")
end)

test("census: a test that shadows `register` on package.path is refused", function()
  local out, ok = inProject(q(lua) .. " " .. q(CENSUS) .. " '' shadow")
  assert(not ok, out)
  assert(out:find("PROBLEM  fx-echo.test.lua: register 'register' resolved to shadow/lib/register.lua,"
    .. " not code/register.lua", 1, true), out)
  assert(not out:find("overrides the register", 1, true), "the source check does not see it: " .. out)
  assertEqual(lastLine(out), "census: 1 gates, 0 tested, 0 untested")
end)

test("census: a test that re-runs itself under another KIT_REGISTER is refused", function()
  local out, ok = inProject(q(lua) .. " " .. q(CENSUS) .. " '' rerun")
  assert(not ok, out)
  assert(out:find("PROBLEM  fx-echo.test.lua: read register 'register_other', not the project's register 'register'",
    1, true), out)
  assertEqual(lastLine(out), "census: 1 gates, 0 tested, 0 untested")
end)

test("census: a test with no register line is refused", function()
  local out, ok = inProject(q(lua) .. " " .. q(CENSUS) .. " '' noline")
  assert(not ok, out)
  assert(out:find("PROBLEM  fx-echo.test.lua: no register line", 1, true), out)
  assertEqual(lastLine(out), "census: 1 gates, 0 tested, 0 untested")
end)

test("census: the register named on the command line is the one checked (the kit's own fixtures)", function()
  local out, ok = sh("cd " .. q(ABS) .. " && unset KIT_REGISTER; " .. q(lua)
    .. " code/kit/census.lua kit-fixtures.census.register_clean sisoTests/kit-fixtures/census/clean")
  assert(ok, out)
  assertEqual(lastLine(out), "census: 3 gates, 3 tested, 0 untested")
  -- the census reads register_other and passes it on: the clean test names
  -- it and its file, so it passes against that register too
  local out2, ok2 = inProject(q(lua) .. " " .. q(CENSUS) .. " register_other sisoTests")
  assert(ok2, out2)
  assertEqual(lastLine(out2), "census: 1 gates, 1 tested, 0 untested")
end)

-- ── run.lua: command or module ────────────────

test("run: a command with no arguments (tests directory sisoTests)", function()
  local out, ok = inProject(q(lua) .. " " .. q(RUN))
  assert(ok, out)
  assert(out:find("ok   gate  fx-echo.test.lua", 1, true), out)
  assertEqual(lastLine(out), "run: 2 suites, 4/4 passed")
end)

test("run: a command with one argument", function()
  local out, ok = inProject(q(lua) .. " " .. q(RUN) .. " sisoTests")
  assert(ok, out)
  assertEqual(lastLine(out), "run: 2 suites, 4/4 passed")
  local out2, ok2 = inProject(q(lua) .. " " .. q(RUN) .. " noline")
  assert(ok2, out2)
  assertEqual(lastLine(out2), "run: 1 suites, 1/1 passed")
end)

test("run: a command with several arguments (the extra ones ignored)", function()
  local out, ok = inProject(q(lua) .. " " .. q(RUN) .. " sisoTests extra more")
  assert(ok, out)
  assertEqual(lastLine(out), "run: 2 suites, 4/4 passed")
  local out2, ok2 = inProject(q(lua) .. " " .. q(RUN) .. " sisoTests kit.run code/kit/x.lua")
  assert(ok2, out2)
  assertEqual(lastLine(out2), "run: 2 suites, 4/4 passed")
end)

test("run: a missing tests directory still fails as a command, with several arguments too", function()
  local out, ok = inProject(q(lua) .. " " .. q(RUN) .. " no-such-dir a b")
  assert(not ok, out)
  assertEqual(lastLine(out), "run: no tests directory no-such-dir")
end)

test("run: required as a module, nothing runs, whatever the requiring script's arguments", function()
  for _, args in ipairs({ "", " a", " a b c" }) do
    local out, ok = inProject(q(lua) .. " require-run.lua" .. args)
    assert(ok, out)
    local n = select(2, args:gsub("%S+", ""))
    assertEqual(out, "module function args=" .. n .. "\n")
  end
  local R2 = require("kit.run")
  assertEqual(type(R2.run), "function")
end)

H.finish("kit-register-check")
