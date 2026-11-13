-- kit-data-frozen — code/kit/data.lua's data.encode reads frozen data
-- (core-api READ-ONLY) as the plain data it presents (kit-api), so
-- encode(freeze(v)) == encode(v) byte for byte, and residue, samples and
-- observer records from a real core.stream Stream serialise as handed out.
--   lua5.4 sisoTests/kit-data-frozen.test.lua
-- Expected values: the encoding of the same data built plainly (the
-- plain-data encoder is pinned by sisoTests/kit-data.test.lua), and for
-- the fixed sample a literal canonical text written by hand.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local test, assert, assertEqual = H.test, H.assert, H.assertEqual
local data = require("kit.data")
local freeze = require("core.freeze")
local Event = require("core.event")
local Gate = require("core.gate")
local Stream = require("core.stream")
local K = require("contracts.kit_api")

local null, array = data.null, data.array

local function enc(v)
  local s, err = data.encode(v)
  if not s then H.fail("encode failed: " .. tostring(err)) end
  return s
end

local function encErr(v, fragment)
  local s, err = data.encode(v)
  assertEqual(s, nil, "encode should fail")
  assert(type(err) == "string" and err:find(fragment, 1, true),
    "error " .. tostring(err) .. " lacks '" .. fragment .. "'")
end

-- encode(freeze(build())) == encode(build()), and both equal `want` when given
local function same(build, want)
  local plain = enc(build())
  local frozen = enc(freeze(build()))
  assertEqual(frozen, plain, "frozen vs plain")
  if want then assertEqual(plain, want, "plain text") end
end

-- build() is refused plainly and frozen, with the same message
local function refusedAlike(build, fragment)
  encErr(build(), fragment)
  local _, plainWhy = data.encode(build())
  local _, frozenWhy = data.encode(freeze(build()))
  assertEqual(frozenWhy, plainWhy, "frozen refusal vs plain refusal")
end

-- ── the contract ──────────────────────────────

test("kit-api check passes with freeze given (frozen data reads as plain)", function()
  local p = K.check({
    data = data, sha256 = require("kit.sha256"), helper = require("kit.helper"), freeze = freeze,
  })
  assert(#p == 0, table.concat(p, "; "))
end)

test("freezing really happened: writes refused, marks answered through getmetatable", function()
  local f = freeze({ a = array({ 1 }), o = {} })
  assert(not pcall(function() f.x = 1 end), "a frozen table accepted a write")
  assert(data.is_array(f.a), "a frozen marked array lost its mark")
  assert(not data.is_array(f.o), "a frozen unmarked table is an array")
  assertEqual(getmetatable(f.o), false, "getmetatable of a frozen unmarked table")
  assert(rawequal(freeze(null), null), "null did not pass through freezing")
  assertEqual(next(f), nil, "next sees contents on a frozen table (read surface is not raw)")
end)

-- ── encode(freeze(v)) == encode(v) ────────────

test("nested objects", function()
  same(function() return { z = { y = { x = { w = "deep" } } }, a = { b = 1, c = { d = true } } } end,
    '{"a":{"b":1,"c":{"d":true}},"z":{"y":{"x":{"w":"deep"}}}}')
end)

test("marked arrays, nested and of objects", function()
  same(function() return array({ array({ 1, 2 }), { k = "v" }, array({ array({}) }) }) end,
    '[[1,2],{"k":"v"},[[]]]')
end)

test("unmarked arrays (keys exactly 1..n)", function()
  same(function() return { l = { 3, 2, 1 }, m = { { 1 }, { "a", "b" } } } end,
    '{"l":[3,2,1],"m":[[1],["a","b"]]}')
end)

test("empty marked arrays, and an empty unmarked table is an object", function()
  same(function() return array({}) end, "[]")
  same(function() return { e = array({}), o = {} } end, '{"e":[],"o":{}}')
  same(function() return {} end, "{}")
end)

test("floats kept apart from integers", function()
  same(function() return array({ 1, 1.0, -0.0, 0.1, 1e21, 5e-324, 2^53 }) end,
    "[1,1.0,-0.0,0.1,1e+21,5e-324,9007199254740992.0]")
end)

test("null, in arrays and objects, keeps its identity", function()
  same(function() return { n = null, l = array({ null, 1, null }) } end, '{"l":[null,1,null],"n":null}')
  local f = freeze({ n = null })
  assert(rawequal(f.n, null), "frozen data's null is not data.null")
end)

test("strings, escapes and UTF-8", function()
  same(function() return { s = "a\"b\\c\n\0", u = "ääkköset ✓", ["ü"] = "" } end,
    '{"s":"a\\"b\\\\c\\n\\u0000","u":"ääkköset ✓","ü":""}')
end)

test("booleans and integers at the edges", function()
  same(function() return array({ true, false, math.maxinteger, math.mininteger, 0 }) end,
    "[true,false,9223372036854775807,-9223372036854775808,0]")
end)

test("a frozen table inside plain data, and plain inside nothing frozen", function()
  local inner = freeze({ k = array({ 1, 2.5 }) })
  assertEqual(enc({ wrap = inner, l = array({ inner }) }), '{"l":[{"k":[1,2.5]}],"wrap":{"k":[1,2.5]}}')
end)

-- ── refusals still refuse when frozen ─────────

test("rejection: a hole in a frozen unmarked list is refused", function()
  refusedAlike(function() return { 1, nil, 3 } end, "is not a string")
end)

test("rejection: mixed keys refused, plain and frozen", function()
  refusedAlike(function() return { 1, 2, x = 3 } end, "is not a string")
end)

test("rejection: a marked array with a hole or a named key, frozen", function()
  refusedAlike(function() local h = array({}); h[1] = 1; h[3] = 3; return h end, "marked array")
  refusedAlike(function() local t = array({ 1 }); t.x = 2; return t end, "marked array has keys other than 1..n")
end)

test("rejection: a non-string, non-number key, frozen", function()
  refusedAlike(function() return { [true] = 1, a = 2 } end, "neither a number nor a string")
  refusedAlike(function() return { [true] = 1 } end, "object key true is not a string")
end)

test("rejection: NaN and invalid UTF-8 inside frozen data", function()
  refusedAlike(function() return { x = 0 / 0 } end, "NaN is not allowed")
  refusedAlike(function() return { x = "\xff" } end, "not valid UTF-8")
  refusedAlike(function() return { x = array({ 1, 1 / 0 }) } end, "Infinity is not allowed")
end)

test("rejection: a foreign metatable is still refused (plain)", function()
  encErr(setmetatable({}, {}), "a table with a metatable is not data")
end)

test("rejection: nesting limit holds on frozen data", function()
  local function nest(n)
    local v = {}
    for _ = 1, n do v = { v } end
    return v
  end
  same(function() return nest(511) end)
  refusedAlike(function() return nest(512) end, "nesting too deep")
end)

-- ── real stream: residue, samples, observer records ──

-- What the gate returns, built plainly each time it is called.
local function payload(v)
  return {
    v = v,
    f = 2.5,
    whole = 3.0,
    n = null,
    s = "ä\t",
    list = { 1, 2, 3 },
    marked = array({ { k = "x" }, array({}) }),
    empty = array({}),
    obj = { deep = { deeper = array({ true, false }) } },
  }
end

local function entryData()
  return { v = 7, tags = array({ "a", "b" }), meta = { at = 1.5 } }
end

local function newStream()
  local s = Stream()
  s:register(Gate("in", function(e)
    return { Event("out", payload(e.data.v)), Event("plain", { only = e.data.tags }) }
  end))
  return s
end

-- plain encodings to compare with
local function plainEvents()
  return {
    out = enc(payload(7)),
    plain = enc({ only = array({ "a", "b" }) }),
    ["in"] = enc(entryData()),
  }
end

local function byType(events)
  local got = {}
  for _, e in ipairs(events) do got[e.type] = enc(e.data) end
  return got
end

test("residue from takeResidue encodes as the plain data", function()
  local s = newStream()
  s:emit(Event("in", entryData()))
  local taken = s:takeResidue()
  assertEqual(#taken, 2, "residue size")
  assert(not pcall(function() taken[1].data.v = 0 end), "taken residue is not frozen")
  local want, got = plainEvents(), byType(taken)
  assertEqual(got.out, want.out, "out")
  assertEqual(got.plain, want.plain, "plain")
end)

test("events from sampleHere encode as the plain data", function()
  local s = newStream()
  s:emit(Event("in", entryData()))
  local residue = s:sampleHere().residue
  assert(not pcall(function() residue[1].data.v = 0 end), "sampled residue is not frozen")
  local want, got = plainEvents(), byType(residue)
  assertEqual(got.out, want.out, "out")
  assertEqual(got.plain, want.plain, "plain")
  -- a whole residue list of { type, data } encodes as built plainly
  local list, plainList = array({}), array({})
  for _, e in ipairs(residue) do list[#list + 1] = { type = e.type, data = e.data } end
  table.sort(list, function(a, b) return a.type < b.type end)
  for _, t in ipairs({ "out", "plain" }) do
    plainList[#plainList + 1] = { type = t, data = t == "out" and payload(7) or { only = array({ "a", "b" }) } }
  end
  assertEqual(enc(list), enc(plainList), "residue list")
end)

test("records' data from an observer batch encode as the plain data", function()
  local s = newStream()
  local batches = {}
  s:attach(function(b) batches[#batches + 1] = b end)
  s:emit(Event("in", entryData()))
  assertEqual(#batches, 1, "batches")
  local records = batches[1].records
  assertEqual(#records, 3, "records")
  local want, seen = plainEvents(), 0
  for _, r in ipairs(records) do
    assert(not pcall(function() r.data.v = 0 end), "record data is not frozen")
    assertEqual(enc(r.data), want[r.eventType], "record " .. r.eventType)
    seen = seen + 1
  end
  assertEqual(seen, 3, "records compared")
end)

H.finish("kit-data-frozen")
