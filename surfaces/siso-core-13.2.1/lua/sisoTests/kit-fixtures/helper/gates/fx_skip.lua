-- Fixture gate: passes v on, or drops the event when data.skip is set.
local Event = require("core.event")
local Gate = require("core.gate")

return Gate("fx:skip", function(event)
  if event.data.skip then return nil end
  return { Event("fx:kept", { v = event.data.v }) }
end, { emits = { "fx:kept" }, rejects = {} })
