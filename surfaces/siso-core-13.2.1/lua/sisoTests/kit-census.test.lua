-- kit-census — code/kit/census.lua: test file names, loading the register,
-- the type graph (edges, cycles, self-loops, terminal types), recognising
-- per-gate tests (run.lua's rule, the helper's gate() call, register
-- overrides, orphans), reading a run, and the census command end to end
-- on the fixture projects under sisoTests/kit-fixtures/census/ (fx:
-- signatures, not project gates): every problem fires and the clean
-- fixture passes. Expected outputs are worked by hand from the fixtures.
--   lua5.4 sisoTests/kit-census.test.lua      (from the project root)
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local test, assert, assertEqual = H.test, H.assert, H.assertEqual
local C = require("kit.census")
local R = require("kit.run")
local Gate = require("core.gate")

local lua = (arg and arg[-1]) or "lua5.4"
local q = R.shellQuote
local FX = "sisoTests/kit-fixtures/census/"
local CLEAN = "kit-fixtures.census.register_clean"

local function has(list, needle)
  for _, s in ipairs(list) do if s:find(needle, 1, true) then return true end end
  return false
end

local function joined(list) return table.concat(list, " | ") end

local function sh(cmd)
  local h = io.popen(cmd .. " 2>&1")
  local out = h:read("a")
  local ok = h:close()
  return out, ok and true or false
end

local function lastLine(out)
  local last = nil
  for line in out:gmatch("[^\n]+") do last = line end
  return last
end

-- The census command, from the project root.
local function census(register, dir)
  return sh("cd " .. q(root == "" and "." or root) .. " && " .. q(lua) .. " code/kit/census.lua "
    .. q(register) .. " " .. q(FX .. dir))
end

local function entry(sig, shape) return { signature = sig, gate = Gate(sig, function() end, shape) } end

-- ── test file names ───────────────────────────

test("testFile: ':' becomes '-'", function()
  assertEqual(C.testFile("app:parse"), "app-parse.test.lua")
  assertEqual(C.testFile("fx:cyc_a"), "fx-cyc_a.test.lua")
end)

test("testFile: other awkward characters become '-'", function()
  assertEqual(C.testFile("fx:a/b c"), "fx-a-b-c.test.lua")
end)

-- ── the register ──────────────────────────────

test("registerName: the argument, then KIT_REGISTER, then `register`", function()
  assertEqual((C.registerName("x.reg")), "x.reg")
  local probe = "package.path='code/?.lua;sisoTests/?.lua;'..package.path; "
    .. "local n, d = require('kit.census').registerName(''); print(n, d)"
  local out = sh("cd " .. q(root == "" and "." or root) .. " && KIT_REGISTER=" .. CLEAN .. " " .. q(lua) .. " -e " .. q(probe))
  assertEqual(out, CLEAN .. "\tfalse\n")
  local out2 = sh("cd " .. q(root == "" and "." or root) .. " && unset KIT_REGISTER; " .. q(lua) .. " -e " .. q(probe))
  assertEqual(out2, "register\ttrue\n")
end)

test("loadGates: the clean fixture register, sorted, no problems", function()
  local gates, problems, found = C.loadGates(CLEAN)
  assert(found, "found")
  assertEqual(#problems, 0, joined(problems))
  assertEqual(#gates, 3)
  assertEqual(gates[1].signature .. " " .. gates[2].signature .. " " .. gates[3].signature,
    "fx:double fx:route fx:start")
  assertEqual(gates[3].module, "kit-fixtures.census.gates.fx_start")
end)

test("loadGates: a register that is not on package.path is not found", function()
  local gates, problems, found = C.loadGates("kit-fixtures.census.register_none")
  assertEqual(found, false)
  assertEqual(#gates, 0)
  assertEqual(#problems, 0)
end)

test("loadGates: a gate without a shape is a problem", function()
  local gates, problems = C.loadGates("kit-fixtures.census.register_bare")
  assertEqual(#gates, 1)
  assert(has(problems, "gate 'fx:bare' declares no shape"), joined(problems))
end)

test("loadGates: duplicates, non-gates, missing modules and shared file names are problems", function()
  package.loaded["census-probe.a"] = Gate("p:a", function() end, { emits = {}, rejects = {} })
  package.loaded["census-probe.a2"] = Gate("p:a", function() end, { emits = {}, rejects = {} })
  package.loaded["census-probe.b"] = Gate("p-a", function() end, { emits = {}, rejects = {} })
  package.loaded["census-probe.not"] = { signature = "p:x" }
  package.loaded["census-probe.reg"] = {
    "census-probe.a", "census-probe.a2", "census-probe.b", "census-probe.not", "census-probe.missing",
  }
  local gates, problems, found = C.loadGates("census-probe.reg")
  assert(found, "found")
  assertEqual(#gates, 2)
  assert(has(problems, "signature 'p:a' registered twice"), joined(problems))
  assert(has(problems, "module 'census-probe.not' in census-probe.reg is not a gate"), joined(problems))
  assert(has(problems, "cannot load gate module 'census-probe.missing'"), joined(problems))
  assert(has(problems, "share one test file name p-a.test.lua"), joined(problems))
  package.loaded["census-probe.bad"] = { 1, 2 }
  local _, p2 = C.loadGates("census-probe.bad")
  assert(has(p2, "does not return a list of gate module names"), joined(p2))
end)

-- ── the type graph ────────────────────────────

test("graph: no gates → the only terminal type is error", function()
  local g = C.graph({})
  assertEqual(g.nodes, 0)
  assertEqual(#g.edges, 0)
  assertEqual(#g.cycles, 0)
  assertEqual(table.concat(g.terminal, ","), "error")
end)

test("graph: a chain gives an edge; claimed types are not terminal", function()
  local g = C.graph({
    entry("a", { emits = { "b" }, rejects = { "error" } }),
    entry("b", { emits = { "c" }, rejects = {} }),
  })
  assertEqual(#g.edges, 1)
  assertEqual(g.edges[1].from .. ">" .. g.edges[1].to, "a>b")
  assertEqual(#g.cycles, 0)
  assertEqual(#g.problems, 0)
  assertEqual(table.concat(g.terminal, ","), "c,error")
end)

test("graph: rejection types are terminal types", function()
  local g = C.graph({ entry("a", { emits = { "b" }, rejects = { "a:rejected" } }) })
  assertEqual(table.concat(g.terminal, ","), "a:rejected,b,error")
end)

test("graph: an indirect cycle is named along its path", function()
  local g = C.graph({
    entry("a", { emits = { "b" }, rejects = {} }),
    entry("b", { emits = { "c" }, rejects = {} }),
    entry("c", { emits = { "a" }, rejects = {} }),
  })
  assertEqual(#g.cycles, 1)
  assertEqual(g.cycles[1], "a → b → c → a")
end)

test("graph: a cycle through a rejection type is found", function()
  local g = C.graph({
    entry("a", { emits = { "b" }, rejects = {} }),
    entry("b", { emits = { "x" }, rejects = { "a" } }),
  })
  assertEqual(#g.cycles, 1)
  assertEqual(g.cycles[1], "a → b → a")
end)

test("graph: a declared self-loop is a problem, in emits or in rejects (no retries in core 12)", function()
  local g1 = C.graph({ entry("p", { emits = { "p", "done" }, rejects = {}, retries = true }) })
  assert(has(g1.problems, "gate 'p' declares a self-loop"), joined(g1.problems))
  assertEqual(table.concat(g1.selfLoops, ","), "p")
  assertEqual(#g1.cycles, 0)
  local g2 = C.graph({ entry("p", { emits = { "done" }, rejects = { "p" } }) })
  assert(has(g2.problems, "SISO_SELF_LOOP"), joined(g2.problems))
end)

test("graph: sibling branches to one type are not a cycle", function()
  local g = C.graph({
    entry("a", { emits = { "b", "c" }, rejects = {} }),
    entry("b", { emits = { "d" }, rejects = {} }),
    entry("c", { emits = { "d" }, rejects = {} }),
    entry("d", { emits = { "out" }, rejects = {} }),
  })
  assertEqual(#g.edges, 4)
  assertEqual(#g.cycles, 0)
  assertEqual(table.concat(g.terminal, ","), "error,out")
end)

-- ── recognising per-gate tests ────────────────

local GOOD = 'local helper = require("kit.helper")\nlocal g = helper.gate("fx:x")\nhelper.done()\n'

test("a per-gate test is what run.lua says it is", function()
  assert(R.isGateTestSource(GOOD), "run.lua's rule")
  assert(C.obtainsGate(GOOD, "fx:x"), "obtains fx:x")
  assert(not C.obtainsGate(GOOD, "fx:y"), "not fx:y")
  assert(C.obtainsGate("local T = require('kit.helper')\nlocal g = T.gate ( 'fx:x' )\n", "fx:x"), "single quotes, spaces")
end)

test("a gate() call in a comment does not obtain the gate", function()
  local src = 'local helper = require("kit.helper")\n-- helper.gate("fx:x")\nhelper.done()\n'
  assert(not C.obtainsGate(src, "fx:x"))
end)

test("overriding the register: use_register, package.loaded/preload/searchers", function()
  assertEqual(C.overridesRegister(GOOD), nil)
  assertEqual(C.overridesRegister(GOOD .. 'helper.use_register("x")\n'), "use_register")
  assertEqual(C.overridesRegister(GOOD .. 'helper["use_register"]("x")\n'), "use_register")
  assertEqual(C.overridesRegister(GOOD .. 'package.loaded["register"] = {}\n'), "package.loaded")
  assertEqual(C.overridesRegister(GOOD .. 'package . preload.register = f\n'), "package.preload")
  assertEqual(C.overridesRegister(GOOD .. 'table.insert(package.searchers, 1, f)\n'), "package.searchers")
  assertEqual(C.overridesRegister(GOOD .. '-- never helper.use_register here\n'), nil)
end)

test("checkTests: the clean fixture — every gate tested; plain suites and fixtures/ are not orphans", function()
  local gates = C.loadGates(CLEAN)
  local tested, untested, problems = C.checkTests(gates, root .. FX .. "clean/")
  assertEqual(#tested, 3)
  assertEqual(#untested, 0)
  assertEqual(#problems, 0, joined(problems))
end)

test("checkTests: untested, orphan, not through the helper, override", function()
  local gates = C.loadGates(CLEAN)
  local _, untested = C.checkTests(gates, root .. FX .. "untested/")
  assertEqual(table.concat(untested, ","), "fx:start")
  local _, _, p1 = C.checkTests(gates, root .. FX .. "orphan/")
  assert(has(p1, "orphan test file fx-gone.test.lua names no registered gate"), joined(p1))
  local t2, _, p2 = C.checkTests(gates, root .. FX .. "nohelper/")
  assertEqual(#t2, 1)
  assert(has(p2, "fx-start.test.lua does not obtain its gate through kit.helper's gate('fx:start')"), joined(p2))
  assert(has(p2, "fx-route.test.lua is not a per-gate test"), joined(p2))
  local t3, _, p3 = C.checkTests(gates, root .. FX .. "override/")
  assertEqual(#t3, 1)
  assert(has(p3, "fx-double.test.lua overrides the register (use_register)"), joined(p3))
  assert(has(p3, "fx-route.test.lua overrides the register (package.loaded)"), joined(p3))
end)

-- ── reading a run ─────────────────────────────

local DIGEST = "digest: " .. string.rep("0", 64) .. "\n"

test("parseRun/checkRun: a clean run", function()
  local r = C.parseRun("gates: fx:x\nshape: accepted=2 distinct=1 rejections=1 keys=fx:y\n" .. DIGEST .. "fx-x: 3/3 passed\n")
  assertEqual(r.passed, 3)
  assertEqual(r.total, 3)
  assertEqual(r.gates, "fx:x")
  assertEqual(r.keys, "fx:y")
  assertEqual(#C.checkRun("fx:x", r, true, true), 0)
end)

test("checkRun: failures, another gate, no digest, no shape, no accepted case, two shapes", function()
  local function p(out, exitOk, dOk, dWhy) return C.checkRun("x", C.parseRun(out), exitOk, dOk, dWhy) end
  local shape = "shape: accepted=1 distinct=1 rejections=0 keys=a\n"
  assert(has(p("gates: x\n" .. shape .. DIGEST .. "x: 1/2 passed, 1 FAILED\n", false), "tests fail (1/2)"), "fail")
  assert(has(p("gates: x,y\n" .. shape .. DIGEST .. "x: 1/1 passed\n", true), "expected exactly 'x'"), "gates")
  assert(has(p("gates: x\n" .. shape .. "x: 1/1 passed\n", true), "no digest line"), "digest")
  assert(has(p("gates: x\n" .. DIGEST .. "x: 1/1 passed\n", true), "no shape line"), "shape")
  assert(has(p("gates: x\nshape: accepted=0 distinct=0 rejections=1 keys=\n" .. DIGEST .. "x: 1/1 passed\n", true),
    "no accepted case"), "accepted")
  assert(has(p("gates: x\nshape: accepted=2 distinct=2 rejections=0 keys=a;b\n" .. DIGEST .. "x: 2/2 passed\n", true),
    "split it"), "distinct")
  assert(has(p("gates: x\n" .. shape .. DIGEST .. "x: 1/1 passed\n", true, false, "output differs between processes"),
    "output differs between processes"), "digests disagree")
  assert(has(p("", true), "no result line"), "result")
end)

-- ── the census command, end to end ────────────

test("census: the clean fixture project passes", function()
  local out, ok = census(CLEAN, "clean")
  assert(ok, out)
  assert(out:find("  ok    fx:route", 1, true), out)
  assert(out:find("accepted=2 rejections=1 shape=fx:doubled", 1, true), out)
  assert(out:find("type graph: 3 gates, 1 edges, acyclic, 0 self-loops", 1, true), out)
  assert(out:find("terminal types: error, fx:doubled, fx:large, fx:small", 1, true), out)
  assert(not out:find("PROBLEM", 1, true), out)
  assertEqual(lastLine(out), "census: 3 gates, 3 tested, 0 untested")
end)

test("census: an untested gate fails", function()
  local out, ok = census(CLEAN, "untested")
  assert(not ok, out)
  assert(out:find("UNTESTED fx:start (expected " .. FX .. "untested/fx-start.test.lua)", 1, true), out)
  assertEqual(lastLine(out), "census: 3 gates, 2 tested, 1 untested")
end)

test("census: an orphan test fails", function()
  local out, ok = census(CLEAN, "orphan")
  assert(not ok, out)
  assert(out:find("PROBLEM  orphan test file fx-gone.test.lua names no registered gate", 1, true), out)
  assertEqual(lastLine(out), "census: 3 gates, 3 tested, 0 untested")
end)

test("census: a test that does not obtain its gate through the helper fails", function()
  local out, ok = census(CLEAN, "nohelper")
  assert(not ok, out)
  assert(out:find("PROBLEM  fx-start.test.lua does not obtain its gate through kit.helper", 1, true), out)
  assert(out:find("PROBLEM  fx-route.test.lua is not a per-gate test", 1, true), out)
  assertEqual(lastLine(out), "census: 3 gates, 1 tested, 0 untested")
end)

test("census: a failing per-gate test fails", function()
  local out, ok = census(CLEAN, "failing")
  assert(not ok, out)
  assert(out:find("PROBLEM  fx-double.test.lua: tests fail (2/3)", 1, true), out)
  assertEqual(lastLine(out), "census: 3 gates, 2 tested, 0 untested")
end)

test("census: a per-gate test that overrides the register is refused", function()
  local out, ok = census(CLEAN, "override")
  assert(not ok, out)
  assert(out:find("PROBLEM  fx-double.test.lua overrides the register (use_register)", 1, true), out)
  assert(out:find("PROBLEM  fx-route.test.lua overrides the register (package.loaded)", 1, true), out)
  assertEqual(lastLine(out), "census: 3 gates, 1 tested, 0 untested")
end)

test("census: a cycle in the type graph fails, though every gate's test passes", function()
  local out, ok = census("kit-fixtures.census.register_cycle", "cycle")
  assert(not ok, out)
  assert(out:find("  ok    fx:cyc_a", 1, true) and out:find("  ok    fx:cyc_b", 1, true), out)
  assert(out:find("PROBLEM  cycle in the type graph: fx:cyc_a → fx:cyc_b → fx:cyc_a", 1, true), out)
  assert(out:find("type graph: 2 gates, 2 edges, 1 cycles, 0 self-loops", 1, true), out)
  assertEqual(lastLine(out), "census: 2 gates, 2 tested, 0 untested")
end)

test("census: a declared self-loop fails, though the gate's test passes", function()
  local out, ok = census("kit-fixtures.census.register_self", "selfloop")
  assert(not ok, out)
  assert(out:find("  ok    fx:self", 1, true), out)
  assert(out:find("PROBLEM  gate 'fx:self' declares a self-loop", 1, true), out)
  assert(out:find("terminal types: error, fx:self_done", 1, true), out)
  assertEqual(lastLine(out), "census: 1 gates, 1 tested, 0 untested")
end)

test("census: a gate without a shape fails", function()
  local out, ok = census("kit-fixtures.census.register_bare", "bare")
  assert(not ok, out)
  assert(out:find("PROBLEM  gate 'fx:bare' declares no shape", 1, true), out)
  assertEqual(lastLine(out), "census: 1 gates, 0 tested, 0 untested")
end)

test("census: a named register that is not found fails", function()
  local out, ok = census("kit-fixtures.census.register_none", "noregister")
  assert(not ok, out)
  assert(out:find("PROBLEM  register module 'kit-fixtures.census.register_none' not found", 1, true), out)
  assertEqual(lastLine(out), "census: 0 gates, 0 tested, 0 untested")
end)

test("census: no register module (the default name) is no gates, and passes", function()
  local lines = {}
  local ok = C.run("kit-fixtures.census.register_none", root .. FX .. "noregister", lua,
    function(s) lines[#lines + 1] = s end, true)
  assert(ok, joined(lines))
  assertEqual(lines[1], "no register module 'kit-fixtures.census.register_none' found: no gates registered")
  assertEqual(lines[#lines], "census: 0 gates, 0 tested, 0 untested")
end)

test("census: this project, which registers no gates, passes with 0 gates", function()
  if package.searchpath("register", root .. "code/?.lua;" .. root .. "sisoTests/?.lua") then return end -- the project has gates now
  local out, ok = sh("cd " .. q(root == "" and "." or root) .. " && unset KIT_REGISTER; " .. q(lua) .. " code/kit/census.lua")
  assert(ok, out)
  assert(out:find("no register module 'register' found: no gates registered", 1, true), out)
  assertEqual(lastLine(out), "census: 0 gates, 0 tested, 0 untested")
end)

H.finish("kit-census")
