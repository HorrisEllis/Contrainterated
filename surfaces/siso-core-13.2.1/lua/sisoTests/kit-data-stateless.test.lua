-- kit-data-stateless — code/kit/data.lua's marks hand out strings, never
-- a table (kit-api; core-api STATELESS THROUGH THE EVENT, core 13.0.0):
-- a marked array answers getmetatable with "kit.data.array", data.null
-- with "readonly:kit.data.null", and nothing kit.data hands out gives a
-- gate a table it could write and later read back.
--   lua5.4 sisoTests/kit-data-stateless.test.lua
-- Expected values: the strings are the kit-api contract's
-- (code/contracts/kit_api.lua); the counting probe is core-api's
-- check.readonly "count" gate, run here on what kit.data hands out
-- directly, without a stream. Freezing ("readonly:" sentinels passed
-- through) is the stream's and is checked in kit-data-frozen.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local test, assert, assertEqual, assertThrows = H.test, H.assert, H.assertEqual, H.assertThrows
local data = require("kit.data")

local null, array = data.null, data.array
local ARRAY, NULL = "kit.data.array", "readonly:kit.data.null"

-- a table that answers `mark` through getmetatable and reads as `contents`
-- (what a frozen copy of a marked array presents)
local function answering(mark, contents)
  return setmetatable({}, {
    __metatable = mark,
    __index = contents,
    __len = function() return #contents end,
    __pairs = function() return next, contents, nil end,
    __newindex = function() error("read-only", 2) end,
  })
end

-- every table reachable from v, v included (keys and values)
local function tables(v, out, seen)
  out, seen = out or {}, seen or {}
  if type(v) ~= "table" or seen[v] then return out end
  seen[v] = true
  out[#out + 1] = v
  for k, x in pairs(v) do tables(k, out, seen); tables(x, out, seen) end
  return out
end

-- ── what the marks answer ─────────────────────

test("data.null answers getmetatable with \"readonly:kit.data.null\"", function()
  assertEqual(getmetatable(null), NULL)
end)

test("a marked array answers getmetatable with \"kit.data.array\"", function()
  assertEqual(getmetatable(array({ 1 })), ARRAY)
  assertEqual(getmetatable(array({})), ARRAY, "empty")
  assertEqual(getmetatable(array()), ARRAY, "no argument")
end)

test("decoded arrays answer the string mark, decoded null is data.null", function()
  local v = data.decode('{"a":[1,[],{"b":null}],"n":null}')
  assertEqual(getmetatable(v.a), ARRAY)
  assertEqual(getmetatable(v.a[2]), ARRAY)
  assertEqual(getmetatable(v.a[3]), nil, "an object is plain")
  assert(rawequal(v.n, null) and rawequal(v.a[3].b, null), "decoded null is data.null")
end)

test("nothing reachable from what kit.data hands out answers getmetatable with a table", function()
  local handed = {
    null, array(), array({ 1, array({ null }) }),
    data.decode('{"a":[1,[],{"b":null}],"n":null,"o":{"l":[[true]]}}'),
    data.decode("[]"), data.decode("null"),
  }
  for i, h in ipairs(handed) do
    for _, t in ipairs(tables(h)) do
      local mt = getmetatable(t)
      assert(type(mt) ~= "table", "value " .. i .. " reaches a table whose getmetatable is a table")
    end
  end
end)

-- core-api check.readonly's probe: a gate that counts through what the
-- metatables of its input hand out. Run twice on fresh inputs, a second
-- run sees 0 when nothing handed out is writable state.
local function countThroughMarks(input)
  local n = 0
  for _, t in ipairs(tables(input)) do
    pcall(function()
      local mt = getmetatable(t)
      mt.n = (mt.n or 0) + 1
      n = n + mt.n
    end)
  end
  return n
end

test("a gate cannot carry a count from one call to the next through the marks", function()
  local function input() return { l = array({ null, array({}) }), d = data.decode("[null,[1]]"), n = null } end
  countThroughMarks(input())
  assertEqual(countThroughMarks(input()), 0, "second call")
end)

test("the marks cannot be replaced or stripped", function()
  assertThrows(function() setmetatable(array({}), nil) end, "protected metatable")
  assertThrows(function() setmetatable(array({}), {}) end, "protected metatable")
  assertThrows(function() setmetatable(null, nil) end, "protected metatable")
  assertEqual(getmetatable(null), NULL, "still answers after the attempts")
end)

-- ── null is still an immutable sentinel ───────

test("data.null still refuses assignment and still reads as null", function()
  assertThrows(function() null.x = 1 end, "immutable")
  assertThrows(function() null[1] = true end, "immutable")
  assertEqual(next(null), nil, "nothing was written")
  assertEqual(tostring(null), "null")
  assertEqual(data.encode(null), "null")
end)

-- ── data.array and is_array compare the string ─

test("data.array on a marked array returns that array", function()
  local t = array({ 1 })
  assert(rawequal(array(t), t))
  local d = data.decode("[1]")
  assert(rawequal(array(d), d), "a decoded array")
end)

test("data.array on a table that answers the mark (a frozen marked array) returns it", function()
  local f = answering(ARRAY, { 1, 2 })
  assert(rawequal(array(f), f))
end)

test("rejection: data.array raises on any other metatable, table or string", function()
  assertThrows(function() array(setmetatable({}, {})) end, "already has a metatable")
  assertThrows(function() array(setmetatable({}, { __name = "kit.array" })) end, "already has a metatable")
  assertThrows(function() array(answering(NULL, {})) end, "already has a metatable")
  assertThrows(function() array(answering("kit.data.arrayX", {})) end, "already has a metatable")
end)

test("is_array: true exactly for a table answering \"kit.data.array\"", function()
  assert(data.is_array(array({})))
  assert(data.is_array(answering(ARRAY, { 1 })), "a table answering the mark")
  assert(not data.is_array({ 1, 2 }), "unmarked")
  assert(not data.is_array(setmetatable({}, { __name = "kit.array" })), "the old table mark")
  assert(not data.is_array(answering("readonly:kit.data.array", {})), "another string")
  assert(not data.is_array(null), "null")
  assert(not data.is_array(ARRAY), "the string itself is not a table")
end)

test("the mark carries no identity: a second load of kit.data agrees on arrays", function()
  local other = dofile(root .. "code/kit/data.lua")
  assert(not rawequal(other, data), "a separate module instance")
  local a = array({ 1 })
  assert(other.is_array(a), "the other instance sees this one's array")
  assert(data.is_array(other.array({})), "this instance sees the other's array")
  assertEqual(other.encode(a), "[1]")
  assert(rawequal(other.array(a), a), "the other's data.array returns this one's array")
end)

-- ── encode's metatable rule compares the string ─

test("encode reads a table answering \"kit.data.array\" as an array", function()
  assertEqual(data.encode(answering(ARRAY, {})), "[]")
  assertEqual(data.encode(answering(ARRAY, { 1, "x", null })), '[1,"x",null]')
  assertEqual(data.encode({ a = answering(ARRAY, { 2.5 }) }), '{"a":[2.5]}')
end)

test("rejection: encode refuses a table answering any other metatable", function()
  for _, mt in ipairs({ "kit.data.arrayX", "readonly:other", NULL }) do
    local s, why = data.encode(answering(mt, {}))
    assertEqual(s, nil, mt)
    assert(why and why:find("a table with a metatable is not data", 1, true), tostring(why))
  end
  local s, why = data.encode(setmetatable({ 1 }, { __name = "kit.array" }))
  assertEqual(s, nil, "the old table mark")
  assert(why and why:find("a table with a metatable is not data", 1, true), tostring(why))
end)

test("rejection: a marked array with extra keys is still refused", function()
  local t = array({ 1 }); t.x = 2
  local s, why = data.encode(t)
  assertEqual(s, nil)
  assert(why and why:find("marked array has keys other than 1..n", 1, true), tostring(why))
end)

H.finish("kit-data-stateless")
