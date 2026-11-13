-- Fixture gate: emits fx:cyc_a (the other half of the cycle).
local Event = require("core.event")
local Gate = require("core.gate")

return Gate("fx:cyc_b", function(event)
  return Event("fx:cyc_a", { v = event.data.v })
end, { emits = { "fx:cyc_a" }, rejects = {} })
