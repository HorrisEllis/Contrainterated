-- contract test: kit-api (code/contracts/kit_api.lua).
-- The module check names what is missing and what misbehaves, and passes
-- on modules that answer as the contract says. (Stubs stand in for the
-- kit, which kit phases build; the stubs answer only what the check asks.)
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local K = require("contracts.kit_api")

local function joined(list) return table.concat(list, "; ") end
local function has(list, needle)
  for _, s in ipairs(list) do if s:find(needle, 1, true) then return true end end
  return false
end

local function stubs()
  local ArrayMeta = { __metatable = "kit.data.array" }
  local null = setmetatable({}, {
    __metatable = "readonly:kit.data.null",
    __newindex = function() error("immutable", 2) end,
  })
  local data = { null = null }
  function data.array(t)
    if getmetatable(t) == "kit.data.array" then return t end
    return setmetatable(t, ArrayMeta)
  end
  function data.is_array(t) return type(t) == "table" and getmetatable(t) == "kit.data.array" end
  local canonical = '{"a":[1.0,"x",true,null],"b":1,"e":[]}'
  function data.encode(v)
    if type(v) == "table" and v.f then return nil, "a function is not data" end
    return canonical
  end
  function data.decode(s)
    if s ~= canonical then return nil, "stub" end
    return { a = data.array({ 1.0, "x", true, null }), b = 1, e = data.array({}) }
  end
  local sha256 = { hex = function(s)
    return s == "abc" and "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad" or "?"
  end }
  local helper = {}
  for k in pairs(K.EXPORTS.helper) do helper[k] = function() end end
  return { data = data, sha256 = sha256, helper = helper }
end

H.test("modules that answer as the contract says pass", function()
  local p = K.check(stubs())
  H.assert(#p == 0, joined(p))
end)

H.test("missing modules and exports are named", function()
  local m = stubs()
  m.helper.done = nil
  m.sha256 = nil
  local p = K.check(m)
  H.assert(has(p, "kit.helper.done is missing"), joined(p))
  H.assert(has(p, "kit.sha256 is missing"), joined(p))
end)

H.test("a non-canonical encoder is named", function()
  local m = stubs()
  m.data.encode = function() return '{"b":1,"a":[1,"x",true,null],"e":[]}' end
  H.assert(has(K.check(m), "not canonical"), "encoder")
end)

H.test("a null that freezing would wrap is named", function()
  local m = stubs()
  m.data.null = setmetatable({}, {})
  H.assert(has(K.check(m), "readonly:kit.data.null"), "null")
end)

H.test("13.0.0-rc1: marks that hand out a writable metatable are named", function()
  local m = stubs()
  local ArrayMeta = { __name = "kit.array" }
  m.data.null = setmetatable({}, { __readonly = true, __newindex = function() error("immutable", 2) end })
  m.data.array = function(t) return setmetatable(t, ArrayMeta) end
  m.data.is_array = function(t) return getmetatable(t) == ArrayMeta end
  local p = K.check(m)
  H.assert(has(p, "getmetatable(data.null) answers table"), joined(p))
  H.assert(has(p, "a marked array answers getmetatable with table"), joined(p))
end)

H.test("with freeze given, an encoder that cannot read frozen data is named", function()
  local m = stubs()
  m.freeze = function(v) return setmetatable({}, { __index = v, __pairs = function() return next, v, nil end }) end
  local encode = m.data.encode
  m.data.encode = function(v)   -- refuses a metatabled table, as an encoder blind to frozen data would
    if getmetatable(v) then return nil, "a table with a metatable is not data" end
    return encode(v)
  end
  local p = K.check(m)
  H.assert(table.concat(p, ";"):find("frozen", 1, true) ~= nil, table.concat(p, "; "))
end)

H.finish("contract kit-api")
