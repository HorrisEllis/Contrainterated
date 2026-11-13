-- A pure module: locals, allowed globals, a layer-legal require.
local Event = require("base.event")
local LIMIT = 3
local function f(event)
  local out = {}
  for i, v in ipairs(event.data.list) do
    if i > LIMIT then break end
    out[#out + 1] = tostring(v) .. string.rep("-", 2) .. math.floor(1.5)
  end
  if #out == 0 then error("a list is required; 'x' requires one") end   -- "require" in a message is not a call
  local note = "require(name) and math.random in a string are not code"
  return Event("x:out", { out = out, note = note })
end
return f
