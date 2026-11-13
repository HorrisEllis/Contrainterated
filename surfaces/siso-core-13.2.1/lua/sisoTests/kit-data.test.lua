-- kit-data — code/kit/data.lua (canonical JSON, the null and array marks)
-- and code/kit/sha256.lua, ported from jää 2.14.4's json and sha256
-- tests, then the kit-api contract check on the real modules.
--   lua5.4 sisoTests/kit-data.test.lua
-- Expected values come from outside the code:
--   Float texts are ECMAScript's Number::toString (Node 22, `String(x)`),
--   with ".0" appended to integral values so the float type survives, and
--   "-0.0" for negative zero (pasted from Node's output in jää Phase 2).
--   SHA-256: "abc" and the 448-bit message are the FIPS 180-4 examples
--   (NIST CSRC); one million 'a' is FIPS 180-2 B.3 / RFC 6234 TEST3; ""
--   and the 896-bit message are widely published vectors; block-boundary
--   lengths are Python 3 hashlib (OpenSSL). Pattern: byte i (0-based) of
--   an n-byte message is (i*31+7) % 256.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local test, assert, assertEqual, assertThrows = H.test, H.assert, H.assertEqual, H.assertThrows
local data = require("kit.data")
local sha256 = require("kit.sha256")
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

local function dec(s)
  local v, err = data.decode(s)
  if err then H.fail("decode failed: " .. err) end
  return v
end

local function decErr(s, fragment)
  local v, err = data.decode(s)
  assertEqual(v, nil, "decode of " .. s .. " should fail")
  assert(type(err) == "string" and err:find(fragment, 1, true),
    "error " .. tostring(err) .. " lacks '" .. fragment .. "'")
end

-- typed structural equality (1 ≠ 1.0, 0.0 ≠ -0.0; same metatables),
-- for assertions only
local function deepEqual(a, b)
  if type(a) ~= type(b) then return false end
  if type(a) == "number" then
    if math.type(a) ~= math.type(b) then return false end
    if a == 0 and b == 0 and math.type(a) == "float" then return 1 / a == 1 / b end
    return a == b
  end
  if rawequal(a, b) then return true end
  if type(a) ~= "table" or getmetatable(a) ~= getmetatable(b) then return false end
  for k, x in pairs(a) do if not deepEqual(x, rawget(b, k)) then return false end end
  for k in pairs(b) do if rawget(a, k) == nil then return false end end
  return true
end

-- ── exports and the contract ──────────────────

test("kit-api check passes on the real data and sha256 modules", function()
  local helper = {}
  for k in pairs(K.EXPORTS.helper) do helper[k] = function() end end
  local p = K.check({ data = data, sha256 = sha256, helper = helper })
  H.assert(#p == 0, table.concat(p, "; "))
end)

test("exports are exactly the contract's", function()
  for _, m in ipairs({ { "data", data }, { "sha256", sha256 } }) do
    local want = K.EXPORTS[m[1]]
    for k in pairs(m[2]) do assert(want[k] ~= nil, "kit." .. m[1] .. "." .. k .. " is not in kit-api") end
    for k in pairs(want) do assert(m[2][k] ~= nil, "kit." .. m[1] .. "." .. k .. " is missing") end
  end
end)

test("the modules require nothing outside code/kit/", function()
  for _, path in ipairs({ "code/kit/data.lua", "code/kit/sha256.lua" }) do
    local f = io.open(root .. path, "r")
    assert(f ~= nil, "cannot open " .. path)
    local src = f:read("a"); f:close()
    for name in src:gmatch("require%s*%(?%s*[\"']([^\"']+)[\"']") do
      assert(name:match("^kit%."), path .. " requires " .. name)
    end
  end
end)

-- ── marks ─────────────────────────────────────

test("null: renders as null, cannot be written to, answers \"readonly:kit.data.null\"", function()
  assertEqual(tostring(null), "null")
  assertThrows(function() null.x = 1 end, "immutable")
  assertEqual(enc(null), "null")
  assertEqual(getmetatable(null), "readonly:kit.data.null")
end)

test("array: marks, is idempotent, rejects a foreign metatable", function()
  local t = array({ 1 })
  assert(data.is_array(t))
  assertEqual(getmetatable(t), "kit.data.array")
  assert(rawequal(array(t), t))
  assert(data.is_array(array()))
  assert(not data.is_array({ 1, 2 }))
  assert(not data.is_array({}))
  assert(not data.is_array("x"))
  assert(not data.is_array(null))
  assertThrows(function() array(setmetatable({}, {})) end, "metatable")
end)

test("array: a table that answers the mark through getmetatable is an array", function()
  -- what a frozen table does (kit-api: the marks survive freezing)
  local mark = getmetatable(array({}))
  local proxy = setmetatable({}, { __metatable = mark, __index = { 1 } })
  assert(data.is_array(proxy))
end)

-- ── encoding scalars ──────────────────────────

test("encode: booleans and integers", function()
  assertEqual(enc(true), "true")
  assertEqual(enc(false), "false")
  assertEqual(enc(0), "0")
  assertEqual(enc(-17), "-17")
  assertEqual(enc(math.maxinteger), "9223372036854775807")
  assertEqual(enc(math.mininteger), "-9223372036854775808")
end)

local FLOATS = {
  -- value, Node 22 String(x) (+ ".0" when integral)
  { 1.0, "1.0" },
  { 0.1, "0.1" },
  { 0.1 + 0.2, "0.30000000000000004" },
  { 100.0, "100.0" },
  { 1.5, "1.5" },
  { -2.5, "-2.5" },
  { 1e20, "100000000000000000000.0" },
  { 1e21, "1e+21" },
  { 1.5e21, "1.5e+21" },
  { 1e-6, "0.000001" },
  { 1.5e-6, "0.0000015" },
  { 1e-7, "1e-7" },
  { 1.23e-7, "1.23e-7" },
  { 2 ^ 53, "9007199254740992.0" },
  { 5e-324, "5e-324" },
  { 1.7976931348623157e308, "1.7976931348623157e+308" },
  { 123456789012345680000.0, "123456789012345680000.0" },
  { 0.0, "0.0" },
  { -0.0, "-0.0" },
  -- exact powers of two: the rounding interval is lopsided, so the
  -- shortest form can be a neighbour of the correctly rounded digits
  { 2.0 ^ -1017, "7.120236347223045e-307" },
  { 2.0 ^ 896, "5.282945311356653e+269" },
  { 2.0 ^ -1022, "2.2250738585072014e-308" },
  { 2.0 ^ 1023, "8.98846567431158e+307" },
  { -(2.0 ^ -1000), "-9.332636185032189e-302" },
}

for _, c in ipairs(FLOATS) do
  test("encode float " .. c[2], function()
    assertEqual(enc(c[1]), c[2])
    assertEqual(enc(array({ c[1] })), "[" .. c[2] .. "]")
  end)
end

test("encode: integers and floats are kept apart", function()
  assertEqual(enc(array({ 1, 1.0, -0.0, 0 })), "[1,1.0,-0.0,0]")
end)

test("encode: NaN and infinities are rejected", function()
  encErr(0 / 0, "NaN")
  encErr(math.huge, "Infinity")
  encErr(-math.huge, "Infinity")
  encErr({ x = { 1.0, math.huge } }, "$.x[2]")
end)

test("encode: string escapes", function()
  assertEqual(enc('a"b\\c'), '"a\\"b\\\\c"')
  assertEqual(enc("\b\f\n\r\t"), '"\\b\\f\\n\\r\\t"')
  assertEqual(enc("\0\1\31"), '"\\u0000\\u0001\\u001f"')
  assertEqual(enc("\127/"), '"\127/"', "DEL and '/' are not escaped")
  assertEqual(enc("h\xC3\xA9 \xF0\x9F\x98\x80"), '"h\xC3\xA9 \xF0\x9F\x98\x80"', "UTF-8 as is")
end)

test("encode: invalid UTF-8 is rejected", function()
  encErr("\xFF", "UTF-8")
  encErr("\xC3", "UTF-8")
  encErr("\xED\xA0\x80", "UTF-8")     -- an encoded surrogate
  encErr({ ["\xFF"] = 1 }, "UTF-8")
end)

-- ── encoding tables ───────────────────────────

test("encode: object keys sorted byte-wise", function()
  assertEqual(enc({ b = 1, a = 2, B = 3, ["\xC3\xA9"] = 4, z = 5, [""] = 6 }),
    '{"":6,"B":3,"a":2,"b":1,"z":5,"\xC3\xA9":4}')
  assertEqual(enc({ ab = 1, a = 2, abcdefghij = 3, abcdefghi = 4, abcdefghik = 5 }),
    '{"a":2,"ab":1,"abcdefghi":4,"abcdefghij":3,"abcdefghik":5}', "prefixes and 8-byte chunks")
end)

test("encode: the same object always gives the same text", function()
  local keys = {}
  for i = 1, 200 do keys[#keys + 1] = "k" .. ((i * 7919) % 1000) end
  local a, b = {}, {}
  for i = 1, #keys do a[keys[i]] = i end
  for i = #keys, 1, -1 do b[keys[i]] = i end
  assertEqual(enc(a), enc(b))
end)

test("encode: arrays — plain 1..n, marked, empty", function()
  assertEqual(enc({ 1, 2, 3 }), "[1,2,3]")
  assertEqual(enc(array({ "x", null, 1.0 })), '["x",null,1.0]')
  assertEqual(enc(array()), "[]")
  assertEqual(enc({}), "{}", "an empty unmarked table is an object")
end)

test("encode: nested structures", function()
  assertEqual(enc({ rows = { { 1, "a" }, { 2, null } }, columns = { "id", "v" }, n = 2 }),
    '{"columns":["id","v"],"n":2,"rows":[[1,"a"],[2,null]]}')
end)

test("encode: a table with holes or mixed keys is rejected", function()
  encErr({ 1, nil, 3 }, "not a string")
  encErr({ 1, a = 2 }, "not a string")
  encErr({ [2] = "x" }, "not a string")
  encErr({ [1.5] = "x" }, "not a string")
end)

test("encode: a marked array with a hole or extra keys is rejected", function()
  encErr(array({ 1, nil, 3 }), "marked array")
  local t = array({ 1, 2 }); t.x = 1
  encErr(t, "marked array")
end)

test("encode: values that are not data are rejected", function()
  encErr(nil, "nil is not a value")
  encErr({ f = function() end }, "function")
  encErr(function() end, "function")
  encErr(io.stdout, "userdata")
  encErr(coroutine.create(function() end), "thread")
  encErr(setmetatable({}, {}), "metatable")
  encErr({ [true] = 1 }, "not a string")
  encErr({ [true] = 1, a = 2 }, "neither a number nor a string")
  local c = {}; c.self = c
  encErr(c, "cycle")
end)

test("encode: nesting up to 512 levels, as decode allows", function()
  local function nest(n) local v = 1 for _ = 1, n do v = { v } end return v end
  local text = enc(nest(512))
  assert(data.decode(text) ~= nil, "512 levels decode")
  encErr(nest(513), "nesting too deep")
  decErr(string.rep("[", 513) .. string.rep("]", 513), "too deep")
end)

test("encode: a deep failure costs little (paths are built lazily)", function()
  local v = 1
  for _ = 1, 20000 do v = { v } end
  local t0 = os.clock()
  encErr(v, "nesting too deep")
  assert(os.clock() - t0 < 1.0, "fast failure")
end)

test("encode: the error names the path", function()
  encErr({ a = { b = { 1, { c = 0 / 0 } } } }, "at $.a.b[2].c")
end)

test("encode: the same table in two places is fine (not a cycle)", function()
  local shared = { 1 }
  assertEqual(enc({ a = shared, b = shared }), '{"a":[1],"b":[1]}')
end)

test("encode: does not modify its input", function()
  local v = { b = { 1, 2 }, a = "x" }
  enc(v)
  assert(deepEqual(v, { b = { 1, 2 }, a = "x" }))
  assert(not data.is_array(v.b), "encoding does not mark arrays")
end)

-- ── decoding ──────────────────────────────────

test("decode: scalars and types", function()
  assertEqual(dec("true"), true)
  assertEqual(dec("false"), false)
  assert(rawequal(dec("null"), null))
  assertEqual(dec("12"), 12)
  assertEqual(dec("-0"), 0)
  assertEqual(dec("1.0"), 1.0)
  assertEqual(dec("1e2"), 100.0)
  assertEqual(dec("1E-2"), 0.01)
  assertEqual(dec('"x"'), "x")
  assertEqual(math.type(dec("12")), "integer")
  assertEqual(math.type(dec("1e2")), "float")
  local z = dec("-0.0")
  assertEqual(z, 0.0)
  assert(1 / z < 0, "-0.0 keeps its sign")
end)

test("decode: 64-bit integer limits", function()
  assertEqual(dec("9223372036854775807"), math.maxinteger)
  assertEqual(dec("-9223372036854775808"), math.mininteger)
  decErr("9223372036854775808", "64-bit")
  decErr("-9223372036854775809", "64-bit")
  decErr("123456789012345678901234", "64-bit")
end)

test("decode: arrays come back marked; objects plain", function()
  local v = dec('{"a":[1,[],{}],"b":{}}')
  assert(data.is_array(v.a))
  assert(data.is_array(v.a[2]))
  assert(not data.is_array(v.a[3]))
  assert(not data.is_array(v))
  assertEqual(#v.a, 3)
end)

test("decode: nulls inside arrays keep the length", function()
  local v = dec("[null,null,3]")
  assertEqual(#v, 3)
  assert(rawequal(v[1], null) and rawequal(v[2], null))
end)

test("decode: string escapes and \\u, including surrogate pairs", function()
  assertEqual(dec('"a\\"b\\\\c\\/d\\b\\f\\n\\r\\t"'), 'a"b\\c/d\b\f\n\r\t')
  assertEqual(dec('"\\u00e9\\u0041\\u0000"'), "\xC3\xA9A\0")
  assertEqual(dec('"\\ud83d\\ude00"'), "\xF0\x9F\x98\x80")
end)

test("decode: bad strings are rejected", function()
  decErr('"\\ud83d"', "surrogate")
  decErr('"\\ude00"', "surrogate")
  decErr('"\\x"', "bad escape")
  decErr('"\\u12"', "\\u")
  decErr('"a\nb"', "control character")
  decErr('"abc', "unterminated")
  decErr('"\xFF"', "UTF-8")
end)

test("decode: bad numbers are rejected", function()
  decErr("01", "leading zero")
  decErr("-01", "leading zero")
  decErr("1.", "fraction")
  decErr(".5", "unexpected character")
  decErr("1e", "exponent")
  decErr("+1", "unexpected character")
  decErr("-", "bad number")
  decErr("1E400", "out of range")
  decErr("NaN", "unexpected character")
  decErr("Infinity", "unexpected character")
end)

test("decode: structure errors", function()
  decErr("", "end of input")
  decErr("   ", "end of input")
  decErr("[1,]", "unexpected character")
  decErr('{"a":1,}', "string key")
  decErr('{"a":1,"a":2}', "duplicate key")
  decErr('{"a" 1}', "':'")
  decErr("[1 2]", "','")
  decErr("{1:2}", "string key")
  decErr("[1] x", "trailing")
  decErr("// c\n1", "unexpected character")
  decErr("tru", "unexpected character")
  decErr(string.rep("[", 600) .. string.rep("]", 600), "too deep")
end)

test("decode: whitespace between tokens is allowed", function()
  local v = dec(' \n\t{ "a" : [ 1 , 2 ] ,\r\n "b" : null } \n')
  assertEqual(enc(v), '{"a":[1,2],"b":null}')
end)

test("decode: a non-string input is an error, not a raise", function()
  local v, err = data.decode(nil)
  assertEqual(v, nil)
  assert(err:find("must be a string", 1, true))
end)

-- ── round trips and canonical form ────────────

test("round trip: canonical text → value → the same text", function()
  local texts = {
    '{"a":[1,2.5,"x",null,true,false],"b":{"c":{}},"d":[]}',
    '[[],[[]],{"":""}]',
    '{"n":-9223372036854775808,"f":-1.5e-300}',
  }
  for _, t in ipairs(texts) do
    local canonical = enc(dec(t))
    assertEqual(enc(dec(canonical)), canonical)
  end
  assertEqual(enc(dec(texts[1])), texts[1])
end)

test("canonicalization: key order, whitespace and number spelling", function()
  assertEqual(enc(dec('{ "b" : 1E2, "a" : 0.50 }')), '{"a":0.5,"b":100.0}')
end)

test("round trip: value → text → an equal value (types kept)", function()
  local v = { rows = array({ array({ 1, 1.0, "1", null }), array() }), meta = {} }
  assert(deepEqual(dec(enc(v)), v))
end)

test("round trip: 10,000 floats come back bit-exact", function()
  local seed = 7
  for i = 1, 10000 do
    seed = (seed * 6364136223846793005 + 1442695040888963407)
    local x = string.unpack("<d", string.pack("<i8", seed))
    if x == x and x ~= math.huge and x ~= -math.huge then
      local y = dec(enc(x))
      assert(math.type(y) == "float" and string.pack("<d", y) == string.pack("<d", x),
        "float " .. string.format("%a", x) .. " at " .. i)
    end
  end
end)

-- ── sha256: FIPS 180-4 and published vectors ──

test("sha256 published vector: empty message", function()
  assertEqual(sha256.hex(""), "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855")
end)

test("sha256 FIPS 180-4: \"abc\" (one block)", function()
  assertEqual(sha256.hex("abc"), "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad")
end)

test("sha256 FIPS 180-4: 448-bit message (two blocks)", function()
  assertEqual(sha256.hex("abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq"),
    "248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1")
end)

test("sha256 published vector: 896-bit message", function()
  assertEqual(sha256.hex("abcdefghbcdefghicdefghijdefghijkefghijklfghijklmghijklmn"
    .. "hijklmnoijklmnopjklmnopqklmnopqrlmnopqrsmnopqrstnopqrstu"),
    "cf5b16a778af8380036ce59e7b0492370b249b11e8f07a51afac45037afee9d1")
end)

test("sha256 FIPS 180-2 B.3 / RFC 6234: one million 'a'", function()
  assertEqual(sha256.hex(string.rep("a", 1000000)),
    "cdc76e5c9914fb9281a1c7e284d73e67f1809a48a497200e046d39ccc7112cd0")
end)

-- ── sha256: padding boundaries (hashlib) ──────

local function pattern(n)
  local b = {}
  for i = 0, n - 1 do b[#b + 1] = string.char((i * 31 + 7) % 256) end
  return table.concat(b)
end

local BOUNDARY = {
  { 1,    "ca358758f6d27e6cf45272937977a748fd88391db679ceda7dc7bf1f005ee879" },
  { 55,   "8aa994584139d128848eeebc4e815639ba5ab6e6e39574195a63ac4f14f7c43b" },
  { 56,   "ad574708f75c044c9b85de64cb568ee7711ff4f36448c6242f053ba8f6cc2b63" },
  { 57,   "5b46e502092be01b1100193e089fdda95638c12e19a1d24f308eb2c3d3ae849d" },
  { 63,   "280ed3e8ff1df845b2e7dfe6ac6cee817bef20e783cc65abc41b818b4d2fe076" },
  { 64,   "c6ab9724ade5b6a7a1edfffb12f3aa9181351355af8fd08c919952ad211339dd" },
  { 65,   "788367c73c7ddf4c53f65e68cc0d943e6227ab55b0e78ba63ace822b1c6301c0" },
  { 111,  "dd1413178fb627f9abbc041ffe39c44aa7aaa0e2e6d2ca5c4528ac7073a2da45" },
  { 119,  "3d610547d68216dedf7435a4fb6260353911f6b3fd3f18805ddb8be285d726fe" },
  { 120,  "1f80156a804cb7862ad113e8200e9d74499723e7c7854d5f48776d3148e09656" },
  { 127,  "192409cd280e14b743642ad1343fbd3e82d9305de72c078117745a679210cc3d" },
  { 128,  "cc548ca2dec1f6fe4f58b2e27aa9c7521607df1130d140b55a4dad0665302356" },
  { 129,  "81e89a7b2911aaa7795f9e3d4910cb47d6cd2b00d83b8399481527261a1a7519" },
  { 1000, "5097e7d587352f5097062ae679f37bda5802d9f875aba14c8cb4d1a188ada179" },
}

for _, c in ipairs(BOUNDARY) do
  test("sha256 hashlib: " .. c[1] .. "-byte message", function()
    assertEqual(sha256.hex(pattern(c[1])), c[2])
  end)
end

test("sha256 hashlib: all 256 byte values 0x00..0xFF", function()
  local b = {}
  for i = 0, 255 do b[#b + 1] = string.char(i) end
  assertEqual(sha256.hex(table.concat(b)), "40aff2e9d2d8922e47afd4648e6967497158785fbd1da870e7110266bf944880")
end)

-- ── sha256: API ───────────────────────────────

test("sha256.hex is 64 lowercase hex characters", function()
  local h = sha256.hex("The quick brown fox")
  assertEqual(#h, 64)
  assert(h:match("^[0-9a-f]+$"), h)
end)

test("sha256 calls are independent (no state carried between them)", function()
  local first = sha256.hex("abc")
  sha256.hex(string.rep("x", 200))
  assertEqual(sha256.hex("abc"), first)
end)

test("sha256: a non-string input raises", function()
  assertThrows(function() sha256.hex(nil) end, "must be a string")
  assertThrows(function() sha256.hex(12) end, "must be a string")
end)

H.finish("kit-data")
