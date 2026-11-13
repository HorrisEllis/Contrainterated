-- Fixture gate: emits fx:cyc_b (half of a cycle).
local Event = require("core.event")
local Gate = require("core.gate")

return Gate("fx:cyc_a", function(event)
  return Event("fx:cyc_b", { v = event.data.v })
end, { emits = { "fx:cyc_b" }, rejects = {} })
