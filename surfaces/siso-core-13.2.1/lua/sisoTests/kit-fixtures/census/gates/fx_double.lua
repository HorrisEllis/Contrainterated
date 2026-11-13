-- Fixture gate: doubles an integer; aborts on anything else.
local Event = require("core.event")
local Gate = require("core.gate")

return Gate("fx:double", function(event)
  local x = event.data.x
  if math.type(x) ~= "integer" then
    return Event("error", { source = "fx:double", code = "BAD_ARGUMENT", message = "x must be an integer" })
  end
  return Event("fx:doubled", { value = x * 2 })
end, { emits = { "fx:doubled" }, rejects = { "error" } })
