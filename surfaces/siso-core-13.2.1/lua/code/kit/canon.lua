--[[
kit.canon — a typed, deterministic rendering of test data (contract
kit-api). The helper uses it to compare whole outputs and to see an
input that a gate changed. Generalised from jää 2.14.4
(sisoTests/tools/canon.lua).

  local canon = require("kit.canon")
  canon.render(v) → text        raises on what is not data
  canon.try(v)    → text | nil, why
  canon.equal(a, b) → boolean

It is stricter than equality and than canonical JSON:
  - integers and floats differ (i1 vs f1), as math.type does;
  - an Event (core.event) renders as E{…}, so it differs from a plain
    table with the same fields (the stream refuses a non-Event);
  - kit.data.null renders as NULL and a table marked by data.array as
    A{…}: the null sentinel and the array mark are seen (an empty array
    differs from an empty object, NULL from {}), in comparisons and in
    the helper's mutation and repeat checks;
  - table keys are rendered in the sorted order of their renderings, so
    the text never depends on Lua's per-process `pairs` order.
Functions, userdata, threads and cycles are not data: rendering them
raises an error naming the path. Marks and Event are read through
getmetatable, so a read-only (frozen) value renders as its original.
]]

local Event = require("core.event")
local data = require("kit.data")

local C = {}

local render

render = function(v, seen, path)
  local t = type(v)
  if t == "nil" then return "nil" end
  if t == "boolean" then return v and "true" or "false" end
  if t == "number" then
    if math.type(v) == "integer" then return "i" .. string.format("%d", v) end
    if v ~= v then return "f:nan" end
    if v == math.huge then return "f:inf" end
    if v == -math.huge then return "f:-inf" end
    return "f" .. string.format("%.17g", v)
  end
  if t == "string" then return string.format("%q", v) end
  if t ~= "table" then
    error("canon: not data at " .. path .. " (" .. t .. ")", 0)
  end
  if rawequal(v, data.null) then return "NULL" end
  if seen[v] then error("canon: cycle at " .. path, 0) end
  seen[v] = true
  local parts = {}
  for k, val in pairs(v) do
    local rk = render(k, {}, path .. "<key>")
    parts[#parts + 1] = { rk, rk .. "=" .. render(val, seen, path .. "." .. tostring(k)) }
  end
  seen[v] = nil
  -- renderings of distinct keys are distinct, so this order is total
  table.sort(parts, function(a, b) return a[1] < b[1] end)
  local body = {}
  for i, p in ipairs(parts) do body[i] = p[2] end
  local prefix = Event.is(v) and "E{" or (data.is_array(v) and "A{" or "{")
  return prefix .. table.concat(body, ",") .. "}"
end

function C.render(v)
  return render(v, {}, "$")
end

function C.try(v)
  local ok, res = pcall(render, v, {}, "$")
  if ok then return res end
  return nil, res
end

function C.equal(a, b)
  return C.render(a) == C.render(b)
end

return C
