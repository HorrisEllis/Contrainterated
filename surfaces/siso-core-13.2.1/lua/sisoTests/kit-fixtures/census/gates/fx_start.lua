-- Fixture gate: starts a chain, asking fx:double to double n.
local Event = require("core.event")
local Gate = require("core.gate")

return Gate("fx:start", function(event)
  return Event("fx:double", { x = event.data.n })
end, { emits = { "fx:double" }, rejects = {} })
