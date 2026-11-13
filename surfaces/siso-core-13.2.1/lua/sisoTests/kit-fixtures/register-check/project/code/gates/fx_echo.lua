-- Fixture gate: echoes a string; aborts on anything else.
local Event = require("core.event")
local Gate = require("core.gate")

return Gate("fx:echo", function(event)
  local text = event.data.text
  if type(text) ~= "string" then
    return Event("error", { source = "fx:echo", code = "BAD_ARGUMENT", message = "text must be a string" })
  end
  return Event("fx:echoed", { text = text })
end, { emits = { "fx:echoed" }, rejects = { "error" } })
