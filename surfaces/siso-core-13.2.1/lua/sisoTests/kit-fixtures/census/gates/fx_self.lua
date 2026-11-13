-- Fixture gate: declares its own type among its rejections (a self-loop
-- the stream would reject), though no case returns it.
local Event = require("core.event")
local Gate = require("core.gate")

return Gate("fx:self", function(event)
  local n = event.data.n
  if math.type(n) ~= "integer" then
    return Event("error", { source = "fx:self", code = "BAD_ARGUMENT", message = "n must be an integer" })
  end
  return Event("fx:self_done", { n = n })
end, { emits = { "fx:self_done" }, rejects = { "error", "fx:self" } })
