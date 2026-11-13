-- kit-helper — code/kit/helper.lua, canon.lua, digest.lua and run.lua:
-- every check of the per-gate helper fires, the census lines and the
-- digest are printed as kit-api says, the two-process digest catches
-- output that follows `pairs` order, and run.lua groups, skips fixtures
-- and totals. Uses the fixture register and gates under
-- sisoTests/kit-fixtures/helper/ (fx: signatures, not project gates) and
-- inline fixture gates. Ends with the kit-api module check on the real
-- modules.
--   lua5.4 sisoTests/kit-helper.test.lua      (from the project root)
-- Expected digests are SHA-256 values from coreutils `sha256sum` over the
-- canonical JSON written out by hand.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local test, assert, assertEqual = H.test, H.assert, H.assertEqual
local helper = require("kit.helper")
local canon = require("kit.canon")
local D = require("kit.digest")
local R = require("kit.run")
local data = require("kit.data")
local Event = require("core.event")
local Gate = require("core.gate")

local lua = (arg and arg[-1]) or "lua5.4"
local fx = root .. "sisoTests/kit-fixtures/helper/"
local REGISTER = "kit-fixtures.helper.register"

helper.use_register(REGISTER)

local function shaped(sig, fn, shape) return Gate(sig, fn, shape) end
local OK = { emits = { "ok" }, rejects = {} }

-- Assert helper.check fails and its message contains `needle`.
local function rejects(kind, g, event, expected, needle)
  local ok, msg = helper.check(kind, g, event, expected)
  assert(not ok, "expected the check to fail (" .. needle .. ")")
  assert(msg and msg:find(needle, 1, true), "message '" .. tostring(msg) .. "' lacks '" .. needle .. "'")
end

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

-- ── obtaining gates ───────────────────────────

test("helper.gate returns the registered gate by signature", function()
  local g = helper.gate("fx:double")
  assertEqual(g.signature, "fx:double")
  assertEqual(g, require("kit-fixtures.helper.gates.fx_double"), "the module's own Gate, loaded once")
end)

test("helper.gate raises for a signature not in the register", function()
  H.assertThrows(function() helper.gate("fx:nope") end, "is not in register " .. REGISTER)
end)

test("a register naming one signature twice is refused", function()
  helper.use_register("kit-fixtures.helper.register_twice")
  H.assertThrows(function() helper.gate("fx:double") end, "registered twice")
  helper.use_register(REGISTER)
end)

test("a missing register module is named", function()
  helper.use_register("kit-fixtures.helper.no_such_register")
  H.assertThrows(function() helper.gate("fx:double") end, "no_such_register")
  helper.use_register(REGISTER)
end)

test("the register is `register` unless KIT_REGISTER names another", function()
  local out = sh("unset KIT_REGISTER; " .. R.shellQuote(lua) .. " -e "
    .. R.shellQuote("package.path='code/?.lua;sisoTests/?.lua;'..package.path; print(require('kit.helper').register_module())"))
  assertEqual(out, "register\n")
  local out2 = sh("KIT_REGISTER=" .. REGISTER .. " " .. R.shellQuote(lua) .. " -e "
    .. R.shellQuote("package.path='code/?.lua;sisoTests/?.lua;'..package.path; print(require('kit.helper').gate('fx:route').signature)"))
  assertEqual(out2, "fx:route\n")
end)

local double = helper.gate("fx:double")
local route = helper.gate("fx:route")
local skip = helper.gate("fx:skip")

-- ── accepted cases on fixture gates ───────────

test("accept: the whole output matches", function()
  local ok, msg, key = helper.check("accept", double, Event("fx:double", { x = 2 }), { Event("fx:doubled", { value = 4 }) })
  assert(ok, msg)
  assertEqual(key, "fx:doubled")
end)

test("accept: a different value fails", function()
  rejects("accept", double, Event("fx:double", { x = 2 }), { Event("fx:doubled", { value = 5 }) }, "expected")
end)

test("accept: integer and float differ (typed comparison)", function()
  rejects("accept", double, Event("fx:double", { x = 2 }), { Event("fx:doubled", { value = 4.0 }) }, "expected")
end)

test("accept: a plain table is not an Event", function()
  rejects("accept", double, Event("fx:double", { x = 2 }), { { type = "fx:doubled", data = { value = 4 } } }, "expected")
end)

test("accept: a wrong event count fails", function()
  rejects("accept", double, Event("fx:double", { x = 2 }), {}, "expected 0 event(s), got 1")
end)

test("accept: a list return is the same output as one Event", function()
  local ok, msg, key = helper.check("accept", skip, Event("fx:skip", { v = 3 }), { Event("fx:kept", { v = 3 }) })
  assert(ok, msg)
  assertEqual(key, "fx:kept")
end)

-- ── shape ─────────────────────────────────────

test("shape: an abort output is not an accepted case", function()
  rejects("accept", double, Event("fx:double", { x = 1.5 }), { helper.error("fx:double", "BAD_ARGUMENT") }, "differ from shape.emits")
end)

test("shape: an accepted case must emit every declared type", function()
  local g = shaped("fx:half", function() return Event("a", {}) end, { emits = { "a", "b" }, rejects = {} })
  rejects("accept", g, Event("fx:half", {}), { Event("a", {}) }, "emitted types {a} differ from shape.emits {a|b}")
end)

test("shape: an accepted case that emits nothing fails", function()
  rejects("accept", skip, Event("fx:skip", { skip = true }), {}, "emitted nothing")
end)

test("shape: a classifier case emits one declared type", function()
  local ok1, m1, k1 = helper.check("accept", route, Event("fx:route", { n = 1 }), { Event("fx:small", { n = 1 }) })
  local ok2, m2, k2 = helper.check("accept", route, Event("fx:route", { n = 99 }), { Event("fx:large", { n = 99 }) })
  assert(ok1, m1)
  assert(ok2, m2)
  assertEqual(k1, "classifier")
  assertEqual(k2, "classifier")
end)

test("shape: a classifier emitting two events, or an undeclared type, fails", function()
  local two = shaped("fx:c2", function() return { Event("a", {}), Event("b", {}) } end,
    { emits = { "a", "b" }, rejects = {}, classifier = true })
  rejects("accept", two, Event("fx:c2", {}), { Event("a", {}), Event("b", {}) }, "exactly one event")
  local other = shaped("fx:c3", function() return Event("z", {}) end,
    { emits = { "a", "b" }, rejects = {}, classifier = true })
  rejects("accept", other, Event("fx:c3", {}), { Event("z", {}) }, "not in shape.emits")
end)

test("shape: abort needs a declared rejection event", function()
  local ok, msg, key = helper.check("abort", double, Event("fx:double", { x = "a" }), { helper.error("fx:double", "BAD_ARGUMENT") })
  assert(ok, msg)
  assertEqual(key, "abort")
  rejects("abort", route, Event("fx:route", { n = 1 }), { Event("fx:small", { n = 1 }) }, "not in shape.rejects")
  rejects("abort", skip, Event("fx:skip", { skip = true }), {}, "at least one rejection event")
end)

test("shape: drop emits nothing", function()
  local ok, msg, key = helper.check("drop", skip, Event("fx:skip", { v = 1, skip = true }), {})
  assert(ok, msg)
  assertEqual(key, "drop")
  rejects("drop", skip, Event("fx:skip", { v = 1 }), {}, "a drop must emit nothing")
end)

test("shape: guard is a drop or a declared rejection", function()
  assert((helper.check("guard", skip, Event("fx:skip", { skip = true }), {})))
  assert((helper.check("guard", double, Event("fx:double", { x = true }), { helper.error("fx:double", "BAD_ARGUMENT") })))
  rejects("guard", skip, Event("fx:skip", { v = 2 }), {}, "not in shape.rejects")
end)

test("shape: a gate without a declared shape fails", function()
  rejects("accept", Gate("fx:bare", function() return Event("ok", {}) end), Event("fx:bare", {}),
    { Event("ok", {}) }, "declares no shape")
  rejects("accept", shaped("fx:half", function() return Event("ok", {}) end, { emits = { "ok" } }), Event("fx:half", {}),
    { Event("ok", {}) }, "declares no shape")
end)

test("shape: an unknown case kind fails", function()
  rejects("accepted", double, Event("fx:double", { x = 1 }), {}, "unknown case kind")
end)

-- ── the input ─────────────────────────────────

test("input: must be an Event", function()
  rejects("accept", double, { type = "fx:double", data = { x = 1 } }, { Event("fx:doubled", { value = 2 }) }, "the input is not an Event")
end)

test("input: must be of the gate's own type", function()
  rejects("accept", double, Event("fx:other", { x = 1 }), { Event("fx:doubled", { value = 2 }) }, "is not the gate's signature")
end)

test("input: the transform receives the event as its only argument", function()
  local argc, seen = nil, nil
  local input = Event("fx:argc", {})
  local g = shaped("fx:argc", function(...)
    argc = select("#", ...)
    seen = ...
    return Event("ok", {})
  end, OK)
  assert((helper.check("accept", g, input, { Event("ok", {}) })))
  assertEqual(argc, 1)
  assert(rawequal(seen, input), "the event itself, not a copy")
end)

-- ── mutation and determinism ──────────────────
-- core 13: Event builds a read-only event, so a gate writing its input
-- raises inside the helper as it does in the stream (core-api READ-ONLY):
-- the case fails as a gate that threw, not as a detected mutation.

test("mutation: a gate that writes its input fails (the write raises)", function()
  local g = shaped("fx:mut", function(event)
    event.data.seen = true
    return Event("ok", {})
  end, OK)
  rejects("accept", g, Event("fx:mut", {}), { Event("ok", {}) }, "SISO_GATE_THREW")
  rejects("accept", g, Event("fx:mut", {}), { Event("ok", {}) }, "read-only")
end)

test("mutation: marking an input table as an array fails (the mark raises)", function()
  local g = shaped("fx:mark", function(e)
    data.array(e.data.rows)
    return Event("ok", {})
  end, OK)
  rejects("accept", g, Event("fx:mark", { rows = {} }), { Event("ok", {}) }, "SISO_GATE_THREW")
end)

test("mutation: writing null into the input fails (the write raises)", function()
  local g = shaped("fx:setnull", function(e)
    e.data.x = data.null
    return Event("ok", {})
  end, OK)
  rejects("accept", g, Event("fx:setnull", { x = {} }), { Event("ok", {}) }, "SISO_GATE_THREW")
  rejects("accept", g, Event("fx:setnull", { x = {} }), { Event("ok", {}) }, "read-only")
end)

test("determinism: two runs that differ fail", function()
  local n = 0
  local g = shaped("fx:nd", function()
    n = n + 1
    return Event("ok", { n = n })
  end, OK)
  rejects("accept", g, Event("fx:nd", {}), { Event("ok", { n = 1 }) }, "not deterministic")
end)

test("determinism: in-process repeats cannot see pairs order (the digest exists for that)", function()
  local scatter = require("kit-fixtures.helper.gates.fx_scatter")
  local ok, msg = helper.check("accept", scatter, Event("fx:scatter", {}), nil)
  assert(not ok and msg:find("expected 0 event", 1, true), "only the expectation differs: " .. tostring(msg))
end)

-- ── what the stream would reject ──────────────

test("non-Event output: a member that is not an Event fails", function()
  local g = shaped("fx:mixed", function() return { Event("ok", {}), "stray" } end, OK)
  rejects("accept", g, Event("fx:mixed", {}), { Event("ok", {}) }, "element 2 of the result is not an Event")
  local plain = shaped("fx:plain", function() return { type = "ok", data = {} } end, OK)
  rejects("accept", plain, Event("fx:plain", {}), { Event("ok", {}) }, "SISO_NOT_EVENT")
end)

test("non-Event output: a list with holes or named keys fails", function()
  local holes = shaped("fx:holes", function() return { Event("ok", {}), nil, Event("ok", {}) } end, OK)
  rejects("accept", holes, Event("fx:holes", {}), { Event("ok", {}), Event("ok", {}) }, "not exactly 1..n")
  local named = shaped("fx:named", function() return { Event("ok", {}), extra = Event("ok", {}) } end, OK)
  rejects("accept", named, Event("fx:named", {}), { Event("ok", {}) }, "not exactly 1..n")
end)

test("non-Event output: a number, a string or false fails", function()
  for _, v in ipairs({ 42, "ok", false }) do
    local g = shaped("fx:val", function() return v end, OK)
    rejects("accept", g, Event("fx:val", {}), { Event("ok", {}) }, "SISO_NOT_EVENT")
  end
end)

test("a gate that raises fails", function()
  local g = shaped("fx:boom", function() error("boom") end, OK)
  rejects("accept", g, Event("fx:boom", {}), { Event("ok", {}) }, "SISO_GATE_THREW")
end)

test("a gate emitting its own type fails (a self-loop)", function()
  local g = shaped("fx:self", function() return Event("fx:self", {}) end, { emits = { "fx:self" }, rejects = {} })
  rejects("accept", g, Event("fx:self", {}), { Event("fx:self", {}) }, "SISO_SELF_LOOP")
end)

-- ── canonical JSON ────────────────────────────

test("canonical JSON: NaN, a function or a mixed table in the output fails", function()
  local nan = shaped("fx:nan", function() return Event("ok", { x = 0 / 0 }) end, OK)
  rejects("accept", nan, Event("fx:nan", {}), { Event("ok", { x = 0 / 0 }) }, "not canonical JSON")
  local fn = shaped("fx:fn", function() return Event("ok", { f = print }) end, OK)
  local ok, msg = helper.check("accept", fn, Event("fx:fn", {}), { Event("ok", {}) })
  assert(not ok and msg ~= nil, "a function in the output fails")
  local mixed = shaped("fx:mix", function() return Event("ok", { t = { 1, a = 2 } }) end, OK)
  rejects("accept", mixed, Event("fx:mix", {}), { Event("ok", { t = { 1, a = 2 } }) }, "not canonical JSON")
  local meta = shaped("fx:meta", function() return Event("ok", { t = setmetatable({}, {}) }) end, OK)
  rejects("accept", meta, Event("fx:meta", {}), { Event("ok", { t = {} }) }, "not canonical JSON")
end)

test("canonical JSON: an empty array and an empty object differ", function()
  local g = shaped("fx:rows", function() return Event("ok", { rows = data.array() }) end, OK)
  rejects("accept", g, Event("fx:rows", {}), { Event("ok", { rows = {} }) }, "A{}")
  local ok, msg = helper.check("accept", g, Event("fx:rows", {}), { Event("ok", { rows = data.array() }) })
  assert(ok, msg)
end)

test("canonical JSON: check returns the output's text", function()
  local ok, _, _, out = helper.check("accept", double, Event("fx:double", { x = 2 }), { Event("fx:doubled", { value = 4 }) })
  assert(ok)
  assertEqual(out, '[{"data":{"value":4},"type":"fx:doubled"}]')
  local _, _, _, none = helper.check("drop", skip, Event("fx:skip", { skip = true }), {})
  assertEqual(none, "[]")
end)

-- ── error events ──────────────────────────────

test("error: matched on source and code, the message not compared", function()
  local ok, msg = helper.check("abort", double, Event("fx:double", { x = "a" }), { helper.error("fx:double", "BAD_ARGUMENT") })
  assert(ok, msg)
end)

test("error: a wrong code or source fails", function()
  rejects("abort", double, Event("fx:double", { x = "a" }), { helper.error("fx:double", "DATATYPE") }, "error code")
  rejects("abort", double, Event("fx:double", { x = "a" }), { helper.error("fx:other", "BAD_ARGUMENT") }, "error source")
end)

test("error: an expected error where an ordinary event came fails", function()
  local g = shaped("fx:noterr", function() return Event("fail", { source = "fx:noterr", code = "X", message = "m" }) end,
    { emits = { "ok" }, rejects = { "fail" } })
  rejects("abort", g, Event("fx:noterr", {}), { helper.error("fx:noterr", "X") }, "expected an error event")
end)

test("error: the convention — string source, code and message, no other field", function()
  local function errGate(sig, d) return shaped(sig, function() return Event("error", d) end, { emits = { "ok" }, rejects = { "error" } }) end
  rejects("abort", errGate("fx:nomsg", { source = "fx:nomsg", code = "X" }), Event("fx:nomsg", {}),
    { helper.error("fx:nomsg", "X") }, "no string message")
  rejects("abort", errGate("fx:nocode", { source = "fx:nocode", message = "m" }), Event("fx:nocode", {}),
    { helper.error("fx:nocode", nil) }, "no string code")
  rejects("abort", errGate("fx:nosrc", { code = "X", message = "m" }), Event("fx:nosrc", {}),
    { helper.error(nil, "X") }, "no string source")
  rejects("abort", errGate("fx:extra", { source = "fx:extra", code = "X", message = "m", stack = "s" }), Event("fx:extra", {}),
    { helper.error("fx:extra", "X") }, "unexpected field 'stack'")
end)

test("error: the convention holds when the expectation is a whole Event too", function()
  local g = shaped("fx:whole", function() return Event("error", { source = "fx:whole", code = "X", message = 7 }) end,
    { emits = { "ok" }, rejects = { "error" } })
  rejects("abort", g, Event("fx:whole", {}), { Event("error", { source = "fx:whole", code = "X", message = 7 }) },
    "no string message")
end)

test("error: detail is compared when expected, refused when not", function()
  local g = shaped("fx:detail", function()
    return Event("error", { source = "fx:detail", code = "X", message = "m", detail = { at = 3 } })
  end, { emits = { "ok" }, rejects = { "error" } })
  assert((helper.check("abort", g, Event("fx:detail", {}), { helper.error("fx:detail", "X", { at = 3 }) })))
  rejects("abort", g, Event("fx:detail", {}), { helper.error("fx:detail", "X", { at = 4 }) }, "error detail")
  rejects("abort", g, Event("fx:detail", {}), { helper.error("fx:detail", "X", { at = 3.0 }) }, "error detail")
  rejects("abort", g, Event("fx:detail", {}), { helper.error("fx:detail", "X") }, "unexpected detail")
  local n = shaped("fx:ndetail", function()
    return Event("error", { source = "fx:ndetail", code = "X", message = "m", detail = data.null })
  end, { emits = { "ok" }, rejects = { "error" } })
  rejects("abort", n, Event("fx:ndetail", {}), { helper.error("fx:ndetail", "X", {}) }, "error detail")
  assert((helper.check("abort", n, Event("fx:ndetail", {}), { helper.error("fx:ndetail", "X", data.null) })))
end)

-- ── canon ─────────────────────────────────────

test("canon: typed, marked, order-free, refuses what is not data", function()
  assertEqual(canon.render({ b = 1, a = 1.0 }), '{"a"=f1,"b"=i1}')
  assert(canon.render(Event("t", {})) ~= canon.render({ type = "t", data = {} }), "an Event is not a plain table")
  assertEqual(canon.render(data.array({})), "A{}")
  assertEqual(canon.render(data.null), "NULL")
  assert(canon.equal({ x = { 1, 2 } }, { x = { 1, 2 } }))
  local cyc = {}
  cyc.self = cyc
  local r, why = canon.try(cyc)
  assert(r == nil and why:find("cycle", 1, true), tostring(why))
  local f, fwhy = canon.try({ f = print })
  assert(f == nil and fwhy:find("not data", 1, true), tostring(fwhy))
end)

-- ── done(): the census lines and the digest ───

local DOUBLE_DIGEST = "45f2ae9360eaa03c3d441a02cce93cd341b86f49a3e8f39c908c51961981d40a"
local function fixtureCmd(register, file)
  return "KIT_REGISTER=" .. register .. " " .. R.shellQuote(lua) .. " " .. R.shellQuote(fx .. file)
end

test("done: prints gates, shape, register and digest lines before the final line", function()
  local out, ok = sh(fixtureCmd(REGISTER, "tests/fx-double.test.lua"))
  assert(ok, "the fixture gate test passes: " .. out)
  local lines = {}
  for line in out:gmatch("[^\n]+") do lines[#lines + 1] = line end
  assertEqual(lines[1], "gates: fx:double")
  assertEqual(lines[2], "shape: accepted=2 distinct=1 rejections=1 keys=fx:doubled")
  assertEqual(lines[3], "register: " .. REGISTER .. " " .. fx .. "register.lua")
  assertEqual(lines[4], "digest: " .. DOUBLE_DIGEST)
  assertEqual(lines[#lines], "fx-double: 3/3 passed")
end)

test("done: a failed case enters the digest as FAILED <label> and the run exits non-zero", function()
  local script = "package.path='code/?.lua;sisoTests/?.lua;'..package.path\n"
    .. "local helper = require('kit.helper')\n"
    .. "local g = helper.gate('fx:double')\n"
    .. "helper.accept('wrong', g, helper.event('fx:double', { x = 1 }), { helper.event('fx:doubled', { value = 3 }) })\n"
    .. "helper.drop('kept', helper.gate('fx:skip'), helper.event('fx:skip', { skip = true }))\n"
    .. "helper.done('probe')\n"
  local out, ok = sh("KIT_REGISTER=" .. REGISTER .. " " .. R.shellQuote(lua) .. " -e " .. R.shellQuote(script))
  assert(not ok, "a failing gate test exits non-zero")
  -- sha256sum of "FAILED wrong" "\n" "[]" (the failed case, then the drop's output)
  assertEqual(D.parse(out), "bac805b9e1d0b6866d1e41b704a94dc983221f31e0c0e9dc19a0e05e782df3a4")
  assert(out:find("gates: fx:double,fx:skip", 1, true), out)
  assert(out:find("shape: accepted=0 distinct=0 rejections=1 keys=", 1, true), out)
  assertEqual(lastLine(out), "probe: 1/2 passed, 1 FAILED")
end)

-- ── the cross-process digest ──────────────────

local A, B = string.rep("a", 64), string.rep("b", 64)

test("digest: parse takes the digest line, or nil", function()
  assertEqual(D.parse("x\ndigest: " .. A .. "\nsuite: 1/1 passed\n"), A)
  assertEqual(D.parse("suite: 1/1 passed\n"), nil)
end)

test("digest: compare passes equal digests and names the cause otherwise", function()
  assert((D.compare("digest: " .. A, "digest: " .. A)))
  local ok, msg = D.compare("digest: " .. A, "digest: " .. B)
  assert(not ok and msg:find("differs between processes", 1, true), tostring(msg))
  local ok2, msg2 = D.compare("suite: 1/1 passed", "digest: " .. A)
  assert(not ok2 and msg2:find("no digest line", 1, true), tostring(msg2))
end)

test("digest: the second process gets a padded environment", function()
  local c = D.second("lua5.4 x.lua")
  assertEqual(c:sub(1, #D.PAD), D.PAD)
  assert(c:find("lua5.4 x.lua", 1, true))
end)

test("digest: a deterministic gate test gives one digest in two processes", function()
  local ok, msg, out, exitOk = D.runTwice(fixtureCmd(REGISTER, "tests/fx-double.test.lua"))
  assert(exitOk, "the fixture test passes: " .. out)
  assert(ok, msg)
end)

test("digest: a pairs-ordered gate passes each run but not the two-process check", function()
  -- fx:scatter emits 24 events in `pairs` order. Each process passes its
  -- own test (the in-process repeat included); the digests differ between
  -- processes. Up to 10 pairs of runs are tried; 24 keys coming out in the
  -- same order 10 times running is negligible.
  local caught, tries = false, 0
  for i = 1, 10 do
    tries = i
    local ok, _, out, exitOk = D.runTwice(fixtureCmd("kit-fixtures.helper.register_scatter", "scatter/fx-scatter.test.lua"))
    assert(exitOk, "each run passes on its own: " .. out)
    if not ok then caught = true break end
  end
  assert(caught, "the digests never differed in " .. tries .. " pairs of runs")
end)

-- ── run.lua ───────────────────────────────────

local function runCmd(register, dir)
  return "KIT_REGISTER=" .. register .. " " .. R.shellQuote(lua) .. " " .. R.shellQuote(root .. "code/kit/run.lua")
    .. " " .. R.shellQuote(dir)
end

test("run: fixtures directories are never entered", function()
  assert(R.underFixtures("kit-fixtures/helper/tests/x.test.lua"))
  assert(R.underFixtures("substrate/fixtures/x.test.lua"))
  assert(R.underFixtures("a/b_fixtures/x.test.lua"))
  assert(not R.underFixtures("fixtures.test.lua"))
  assert(not R.underFixtures("substrate/core.test.lua"))
  local files = R.suiteFiles(fx .. "tests")
  assertEqual(table.concat(files, ","), "fx-double.test.lua,plain.test.lua")
end)

test("run: a gate test is a file that requires kit.helper and calls its done()", function()
  assert(R.isGateTestSource('local helper = require("kit.helper")\nhelper.done()\n'))
  assert(R.isGateTestSource("local T = require 'kit.helper'\nT.done('x')\n"))
  assert(not R.isGateTestSource('local H = require("harness")\nH.finish("x")\n'), "a plain suite")
  assert(not R.isGateTestSource('local helper = require("kit.helper")\n-- helper.done()\nH.finish("x")\n'),
    "a done() in a comment does not count")
  assert(not R.isGateTestSource('local helper = require("kit.helper")\nprint("helper.done()")\n'),
    "a done() in a string does not count")
  assert(R.isGateTest(fx .. "tests/fx-double.test.lua"))
  assert(not R.isGateTest(fx .. "tests/plain.test.lua"))
  assert(not R.isGateTest(root .. "sisoTests/kit-helper.test.lua"), "this suite is not a gate test")
end)

test("run: totals every suite, runs gate tests twice, skips fixtures, exits 0", function()
  local out, ok = sh(runCmd(REGISTER, fx .. "tests"))
  assert(ok, "exit 0: " .. out)
  assert(out:find("ok   suite plain.test.lua", 1, true), out)
  assert(out:find("ok   gate  fx-double.test.lua", 1, true), out)
  assert(not out:find("never", 1, true), "the fixtures directory was entered: " .. out)
  assert(out:find("digests: 1 gate tests, 1 agree across processes", 1, true), out)
  assertEqual(lastLine(out), "run: 2 suites, 5/5 passed")
end)

test("run: a gate test whose digests disagree fails the run", function()
  local caught, out = false, ""
  for _ = 1, 10 do
    local ok
    out, ok = sh(runCmd("kit-fixtures.helper.register_scatter", fx .. "scatter"))
    if not ok then caught = true break end
  end
  assert(caught, "run.lua passed the pairs-ordered gate test 10 times: " .. out)
  assert(out:find("differs between processes", 1, true), out)
  assertEqual(lastLine(out), "run: 1 suites, 1/1 passed, 1 FAILED (fx-scatter.test.lua)")
end)

test("run: a failing suite, or one without a result line, fails the run", function()
  assertEqual(select(2, R.result("x\nplain: 2/3 passed, 1 FAILED\n")), 2)
  assertEqual(R.result("no result here\n"), nil)
  local out, ok = sh(runCmd(REGISTER, fx .. "tests/fixtures"))
  assert(not ok, "a suite that fails fails the run: " .. out)
  assertEqual(lastLine(out), "run: 1 suites, 0/1 passed, 1 FAILED (never.test.lua)")
  local _, okMissing = sh(runCmd(REGISTER, fx .. "no-such-dir"))
  assert(not okMissing, "a missing tests directory fails")
end)

-- ── counted cases and the summary ─────────────

helper.accept("counted accept (fixture)", double, helper.event("fx:double", { x = 5 }), { helper.event("fx:doubled", { value = 10 }) })
helper.abort("counted abort (fixture)", double, helper.event("fx:double", { x = 0.5 }), { helper.error("fx:double", "BAD_ARGUMENT") })

test("summary: gates obtained, accepted shapes, rejections, digest of the outputs in order", function()
  local s = helper.summary()
  assertEqual(s.gates, "fx:double,fx:route,fx:skip")
  assertEqual(s.accepted, 1)
  assertEqual(s.distinct, 1)
  assertEqual(s.keys, "fx:doubled")
  assertEqual(s.rejections, 1)
  -- sha256sum of '[{"data":{"value":10},"type":"fx:doubled"}]' "\n"
  -- '[{"data":{"code":"BAD_ARGUMENT","message":"x must be an integer","source":"fx:double"},"type":"error"}]'
  assertEqual(s.digest, "336fa31087d91d5f50747ac5bbbd7a5cabdff0bc58f141b0717777f8cb66bed6")
end)

-- ── the kit-api module check ──────────────────

test("kit-api: the module check passes on the real modules", function()
  local p = require("contracts.kit_api").check{
    data = require("kit.data"), sha256 = require("kit.sha256"), helper = require("kit.helper"),
  }
  assert(#p == 0, table.concat(p, "; "))
end)

H.finish("kit-helper")
