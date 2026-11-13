--[[
kit.data — plain data and its canonical JSON (kit-api, decision 5).

Ported from jää 2.14.4 lib.json, with the key ordering it took from
lib.util inlined, so this module requires nothing.

  data.null               the JSON null sentinel (a value, not nil),
                          immutable. getmetatable answers the string
                          "readonly:kit.data.null", so core-api READ-ONLY
                          passes it through freezing untouched and
                          `v == data.null` still holds.
  data.array(t) → t       marks t as a JSON array (t may be empty or nil);
                          getmetatable on it answers "kit.data.array".
                          On a table already answering the mark, returns
                          it; on any other metatable, raises.
  data.is_array(t)        true for a table answering "kit.data.array"
                          through getmetatable, so a frozen copy of a
                          marked array is an array too

The marks hand out strings, never a table (core 13.0.0; core-api
STATELESS THROUGH THE EVENT): nothing kit.data hands out gives a gate a
table it could write and later read back. `rawset` on data.null and the
debug library stay the substrate concession R3.
  data.encode(v)          → canonical text | nil, why
  data.decode(s)          → value | nil, why

Canonical form:
  - object keys sorted byte-wise; no whitespace anywhere;
  - integers as digits (Lua integer subtype);
  - floats as the shortest round-trip digits (the closest of them to the
    value when several have the same length), laid out as ECMAScript's
    Number::toString (decimal for 1e-7 <= |x| < 1e21, else d.ddde±n),
    always keeping a "." or an exponent so the type survives: 1.0 is
    "1.0", 1e21 is "1e+21", -0.0 is "-0.0";
  - NaN and ±Inf are rejected;
  - strings: `"` `\` and bytes below 0x20 escaped (\b \f \n \r \t, else
    \u00XX); every other byte as is. Strings must be valid UTF-8
    (RFC 8259 §8.1), checked with Lua's strict utf8.len;
  - a table is an array when marked by data.array(), or when it is
    unmarked and its keys are exactly 1..n with n >= 1. An empty unmarked
    table is an object. Any other table must have only string keys.
  - a table whose getmetatable answers anything but nil, false or the
    array mark is not data and
    is rejected (data.null is recognised first); so are functions,
    userdata, threads, nil and cycles.

Frozen data (core-api READ-ONLY) is encoded as the plain data it
presents, so encode(frozen v) == encode(v) byte for byte. Every table,
frozen or not, is read through the read surface only: contents through
indexing, # and pairs (a frozen table's __pairs), the mark through
getmetatable, where `false` (a frozen table that had no metatable) counts
as no metatable. next, rawget and rawlen are never used: they see an
empty table on a frozen one. This module does not require core.freeze.

Nesting is limited to 512 arrays/objects, in both directions.

Decoding is strict RFC 8259 (no trailing commas, comments, NaN, leading
zeros or duplicate keys; the whole input must be one value). Arrays come
back marked; numbers without "." or exponent come back as integers and
must fit in 64 bits; other numbers come back as floats. \u escapes are
decoded to UTF-8 (surrogate pairs joined; a lone surrogate is an error).

Pure: no state written after load, no I/O. Output never depends on
Lua's table order: `pairs` only collects keys, which are then sorted.
Errors are returned, never raised (data.array raises on a table that
already carries another metatable: that is a caller's bug, not data).
]]

local data = {}

-- ── marks ─────────────────────────────────────

-- The marks are strings (core 13.0.0, STATELESS THROUGH THE EVENT): each
-- metatable below sets __metatable, so getmetatable answers the string
-- and never the table, and setmetatable on a marked value raises. A mark
-- is compared by value, so it carries no identity and holds no state.
local NULL_MARK = "readonly:kit.data.null"
local ARRAY_MARK = "kit.data.array"

data.null = setmetatable({}, {
  __metatable = NULL_MARK,
  __name = "kit.data.null",
  __tostring = function() return "null" end,
  __newindex = function() error("data.null is immutable", 2) end,
})
local null = data.null

local arrayMeta = { __metatable = ARRAY_MARK, __name = ARRAY_MARK }

function data.array(t)
  t = t or {}
  local mt = getmetatable(t)
  if mt == ARRAY_MARK then return t end
  if mt ~= nil then error("data.array: table already has a metatable", 2) end
  return setmetatable(t, arrayMeta)
end

function data.is_array(t)
  return type(t) == "table" and getmetatable(t) == ARRAY_MARK
end

-- ── key order (from jää lib.util) ─────────────

-- Byte-wise "a < b". Lua's `<` on strings calls strcoll, which depends
-- on the C locale; this never does.
local function bytes_lt(a, b)
  if a == b then return false end
  local la, lb = #a, #b
  local n = la < lb and la or lb
  local i = 1
  while i + 7 <= n and a:sub(i, i + 7) == b:sub(i, i + 7) do i = i + 8 end
  while i <= n do
    local x, y = a:byte(i), b:byte(i)
    if x ~= y then return x < y end
    i = i + 1
  end
  return la < lb
end

-- Total order on keys: numbers before strings; numbers by value; strings
-- byte-wise. Any other key raises (caught by the encoder).
local function key_lt(a, b)
  local ta, tb = type(a), type(b)
  if ta == "number" and tb == "number" then return a < b end
  if ta == "string" and tb == "string" then return bytes_lt(a, b) end
  if ta == "number" and tb == "string" then return true end
  if ta == "string" and tb == "number" then return false end
  error("key of type " .. (ta ~= "number" and ta ~= "string" and ta or tb)
    .. " has no defined order", 0)
end

-- A new list of t's keys in key_lt order. Keys are distinct and key_lt
-- is a total order on them, so the result does not depend on the order
-- `pairs` visits them in.
local function sorted_keys(t)
  local ks = {}
  for k in pairs(t) do ks[#ks + 1] = k end
  table.sort(ks, key_lt)
  return ks
end

-- ── floats ────────────────────────────────────

-- Shortest digits d1..dk and exponent n with x = 0.d1..dk × 10^n
-- (ECMAScript's k and n), for finite x > 0. For each length p the
-- correctly rounded p-digit decimal M is tried, and then its neighbours
-- M-1 and M+1: at an exact power of two the rounding interval of x is
-- lopsided, so a neighbour can round-trip when M does not. Among the
-- p-digit decimals that round-trip, the one closest to x is taken
-- (ECMAScript Number::toString, step 5).
local function candidate(c, e)
  return string.format("%de%d", c, e)
end

local function shortest(x)
  for p = 1, 17 do
    -- "." forced as the decimal point, whatever LC_NUMERIC says
    local s = string.format("%." .. (p - 1) .. "e", x):gsub(",", ".")
    local m, e = s:match("^(%d[%.%d]*)e([-+]%d+)$")
    local M = math.tointeger(tonumber((m:gsub("%.", ""))))
    local scale = tonumber(e) - (p - 1)          -- x ≈ M × 10^scale
    local best = nil
    if tonumber(candidate(M, scale)) == x then
      best = M
    else
      local lo, hi = M - 1, M + 1
      local okLo = lo > 0 and tonumber(candidate(lo, scale)) == x
      local okHi = tonumber(candidate(hi, scale)) == x
      if okLo and okHi then
        local dLo = math.abs(x - tonumber(candidate(lo, scale)))
        local dHi = math.abs(tonumber(candidate(hi, scale)) - x)
        best = (dLo <= dHi) and lo or hi
      elseif okLo then best = lo
      elseif okHi then best = hi end
    end
    if best then
      local digits = tostring(best)
      local n = #digits + scale
      digits = digits:gsub("0+$", "")
      if digits == "" then digits = "0" end
      return digits, n
    end
  end
  error("no round-trip form for " .. string.format("%.17g", x), 0)
end

local function formatFloat(x)
  if x ~= x then return nil, "NaN is not allowed" end
  if x == math.huge or x == -math.huge then return nil, "Infinity is not allowed" end
  if x == 0 then
    if 1 / x < 0 then return "-0.0" end
    return "0.0"
  end
  local sign = ""
  if x < 0 then sign, x = "-", -x end
  local d, n = shortest(x)
  local k = #d
  local out
  if k <= n and n <= 21 then
    out = d .. string.rep("0", n - k) .. ".0"
  elseif 0 < n and n <= 21 then
    out = d:sub(1, n) .. "." .. d:sub(n + 1)
  elseif -6 < n and n <= 0 then
    out = "0." .. string.rep("0", -n) .. d
  else
    local e = n - 1
    local es = (e < 0) and ("-" .. tostring(-e)) or ("+" .. tostring(e))
    if k == 1 then
      out = d .. "e" .. es
    else
      out = d:sub(1, 1) .. "." .. d:sub(2) .. "e" .. es
    end
  end
  return sign .. out
end

-- ── encoding ──────────────────────────────────

local ESC = {
  ['"'] = '\\"', ["\\"] = "\\\\", ["\b"] = "\\b", ["\f"] = "\\f",
  ["\n"] = "\\n", ["\r"] = "\\r", ["\t"] = "\\t",
}

local function escapeChar(c)
  return ESC[c] or string.format("\\u%04x", c:byte())
end

-- A path is a chain of {parent, step} nodes, rendered only on error;
-- building strings on every level would cost memory quadratic in depth.
local function pathText(node)
  local steps = {}
  while node do
    steps[#steps + 1] = node[2]
    node = node[1]
  end
  local out = { "$" }
  for i = #steps, 1, -1 do out[#out + 1] = steps[i] end
  return table.concat(out)
end

local function fail(msg, path)
  error({ data = true, message = msg .. " at " .. pathText(path) }, 0)
end

local MAX_DEPTH = 512      -- nested arrays/objects; encode and decode alike

local encodeValue

-- The array length of t if t encodes as an array, else nil, and t's
-- keys in sorted order. Read through indexing, # and pairs only, so a
-- frozen table reads as the plain table it presents.
local function arrayLength(t, path)
  local marked = getmetatable(t) == ARRAY_MARK
  local n, count, allInts = #t, 0, true
  local okKeys, keys = pcall(sorted_keys, t)
  if not okKeys then fail("table has a key that is neither a number nor a string", path) end
  for _, k in ipairs(keys) do
    count = count + 1
    if math.type(k) ~= "integer" then allInts = false end
  end
  if marked then
    if count ~= n or not allInts then fail("marked array has keys other than 1..n", path) end
    for i = 1, n do if t[i] == nil then fail("marked array has a hole", path) end end
    return n, keys
  end
  if n >= 1 and count == n and allInts then
    for i = 1, n do if t[i] == nil then return nil, keys end end
    return n, keys
  end
  return nil, keys
end

encodeValue = function(v, out, seen, path, depth)
  local t = type(v)
  if v == nil then fail("nil is not a value (use data.null)", path) end
  if rawequal(v, null) then out[#out + 1] = "null"; return end
  if t == "boolean" then out[#out + 1] = v and "true" or "false"; return end
  if t == "number" then
    if math.type(v) == "integer" then
      out[#out + 1] = string.format("%d", v)
    else
      local s, err = formatFloat(v)
      if not s then fail(err, path) end
      out[#out + 1] = s
    end
    return
  end
  if t == "string" then
    if not utf8.len(v) then fail("string is not valid UTF-8", path) end
    -- explicit byte ranges: %c would depend on the C locale
    out[#out + 1] = '"' .. v:gsub('[\0-\31"\\]', escapeChar) .. '"'
    return
  end
  if t ~= "table" then fail("a " .. t .. " is not data", path) end
  -- `false` is what a frozen table that had no metatable answers
  local mt = getmetatable(v)
  if mt ~= nil and mt ~= false and mt ~= ARRAY_MARK then fail("a table with a metatable is not data", path) end
  if seen[v] then fail("cycle", path) end
  if depth >= MAX_DEPTH then fail("nesting too deep", path) end
  seen[v] = true
  local n, keys = arrayLength(v, path)
  if n then
    out[#out + 1] = "["
    for i = 1, n do
      if i > 1 then out[#out + 1] = "," end
      encodeValue(v[i], out, seen, { path, "[" .. i .. "]" }, depth + 1)
    end
    out[#out + 1] = "]"
  else
    for _, k in ipairs(keys) do
      if type(k) ~= "string" then
        fail("object key " .. tostring(k) .. " is not a string", path)
      end
    end
    out[#out + 1] = "{"
    for i, k in ipairs(keys) do
      if i > 1 then out[#out + 1] = "," end
      encodeValue(k, out, seen, { path, ".<key>" }, depth + 1)
      out[#out + 1] = ":"
      encodeValue(v[k], out, seen, { path, "." .. k }, depth + 1)
    end
    out[#out + 1] = "}"
  end
  seen[v] = nil
end

function data.encode(v)
  local out = {}
  local ok, err = pcall(encodeValue, v, out, {}, nil, 0)
  if not ok then
    if type(err) == "table" and err.data then return nil, "data.encode: " .. err.message end
    return nil, "data.encode: " .. tostring(err)
  end
  return table.concat(out)
end

-- ── decoding ──────────────────────────────────

local function dfail(msg, pos)
  error({ data = true, message = msg .. " at byte " .. pos }, 0)
end

local function skipWs(s, i)
  local _, e = s:find("^[ \t\n\r]*", i)
  return e + 1
end

local decodeValue

local UNESC = {
  ['"'] = '"', ["\\"] = "\\", ["/"] = "/", b = "\b", f = "\f",
  n = "\n", r = "\r", t = "\t",
}

local function decodeString(s, i)
  -- s:sub(i, i) == '"'
  local parts = {}
  local j = i + 1
  while true do
    local a, b = s:find('^[^"\\\0-\31]+', j)
    if a then parts[#parts + 1] = s:sub(a, b); j = b + 1 end
    local c = s:sub(j, j)
    if c == '"' then
      local str = table.concat(parts)
      if not utf8.len(str) then dfail("string is not valid UTF-8", i) end
      return str, j + 1
    elseif c == "\\" then
      local e = s:sub(j + 1, j + 1)
      local simple = UNESC[e]
      if simple then
        parts[#parts + 1] = simple; j = j + 2
      elseif e == "u" then
        local hex = s:match("^%x%x%x%x", j + 2)
        if not hex then dfail("bad \\u escape", j) end
        local cp = tonumber(hex, 16)
        j = j + 6
        if cp >= 0xD800 and cp <= 0xDBFF then
          local lo = s:match("^\\u(%x%x%x%x)", j)
          local lov = lo and tonumber(lo, 16)
          if not lov or lov < 0xDC00 or lov > 0xDFFF then dfail("lone high surrogate", j) end
          cp = 0x10000 + ((cp - 0xD800) << 10) + (lov - 0xDC00)
          j = j + 6
        elseif cp >= 0xDC00 and cp <= 0xDFFF then
          dfail("lone low surrogate", j)
        end
        parts[#parts + 1] = utf8.char(cp)
      else
        dfail("bad escape", j)
      end
    elseif c == "" then
      dfail("unterminated string", i)
    else
      dfail("control character in string", j)
    end
  end
end

local function decodeNumber(s, i)
  -- validate against the RFC 8259 grammar exactly
  local int, frac, exp = s:match("^(-?%d+)(%.?%d*)([eE]?[-+]?%d*)", i)
  if not int then dfail("bad number", i) end
  if int:match("^-?0%d") then dfail("leading zero", i) end
  if frac == "." then dfail("bad fraction", i) end
  if exp ~= "" and not exp:match("^[eE][-+]?%d+$") then dfail("bad exponent", i) end
  local text = int .. frac .. exp
  local nextPos = i + #text
  if frac == "" and exp == "" then
    local v = math.tointeger(tonumber(text))
    -- tonumber gives a float when the digits overflow 64 bits
    if v == nil or string.format("%d", v) ~= (text:gsub("^%-0$", "0")) then
      dfail("integer out of 64-bit range", i)
    end
    return v, nextPos
  end
  local v = tonumber(text)
  if v == nil then dfail("bad number", i) end
  if math.type(v) == "integer" then v = v + 0.0 end   -- never on -0.0: -0.0 + 0.0 is 0.0
  if v == math.huge or v == -math.huge then dfail("number out of range", i) end
  return v, nextPos
end

decodeValue = function(s, i, depth)
  i = skipWs(s, i)
  local c = s:sub(i, i)
  -- containers only, as in the encoder: at most MAX_DEPTH nested levels
  if (c == "{" or c == "[") and depth >= MAX_DEPTH then dfail("nesting too deep", i) end
  if c == "{" then
    local obj = {}
    i = skipWs(s, i + 1)
    if s:sub(i, i) == "}" then return obj, i + 1 end
    while true do
      if s:sub(i, i) ~= '"' then dfail("expected a string key", i) end
      local k
      k, i = decodeString(s, i)
      i = skipWs(s, i)
      if s:sub(i, i) ~= ":" then dfail("expected ':'", i) end
      local v
      v, i = decodeValue(s, i + 1, depth + 1)
      if obj[k] ~= nil then dfail("duplicate key " .. string.format("%q", k), i) end
      obj[k] = v
      i = skipWs(s, i)
      local d = s:sub(i, i)
      if d == "}" then return obj, i + 1 end
      if d ~= "," then dfail("expected ',' or '}'", i) end
      i = skipWs(s, i + 1)
    end
  elseif c == "[" then
    local arr = data.array({})
    i = skipWs(s, i + 1)
    if s:sub(i, i) == "]" then return arr, i + 1 end
    local n = 0
    while true do
      local v
      v, i = decodeValue(s, i, depth + 1)
      n = n + 1
      arr[n] = v
      i = skipWs(s, i)
      local d = s:sub(i, i)
      if d == "]" then return arr, i + 1 end
      if d ~= "," then dfail("expected ',' or ']'", i) end
      i = i + 1
    end
  elseif c == '"' then
    return decodeString(s, i)
  elseif c == "-" or c:match("^%d$") then
    return decodeNumber(s, i)
  elseif s:sub(i, i + 3) == "true" then
    return true, i + 4
  elseif s:sub(i, i + 4) == "false" then
    return false, i + 5
  elseif s:sub(i, i + 3) == "null" then
    return null, i + 4
  elseif c == "" then
    dfail("unexpected end of input", i)
  end
  dfail("unexpected character", i)
end

function data.decode(s)
  if type(s) ~= "string" then return nil, "data.decode: input must be a string" end
  local ok, v, i = pcall(decodeValue, s, 1, 0)
  if not ok then
    if type(v) == "table" and v.data then return nil, "data.decode: " .. v.message end
    return nil, "data.decode: " .. tostring(v)
  end
  i = skipWs(s, i)
  if i <= #s then return nil, "data.decode: trailing characters at byte " .. i end
  return v
end

return data
