-- Fixture classifier: one event, small or large, by n.
local Event = require("core.event")
local Gate = require("core.gate")

return Gate("fx:route", function(event)
  local n = event.data.n
  if n < 10 then return Event("fx:small", { n = n }) end
  return Event("fx:large", { n = n })
end, { emits = { "fx:small", "fx:large" }, rejects = {}, classifier = true })
