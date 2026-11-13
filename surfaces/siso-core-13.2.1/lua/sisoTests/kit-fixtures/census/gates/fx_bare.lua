-- Fixture gate with no shape.
local Event = require("core.event")
local Gate = require("core.gate")

return Gate("fx:bare", function(event)
  return Event("fx:bared", { v = event.data.v })
end)
