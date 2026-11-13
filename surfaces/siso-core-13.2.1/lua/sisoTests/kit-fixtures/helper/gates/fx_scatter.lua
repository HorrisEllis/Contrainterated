-- Fixture gate that is deterministic within a process but not across
-- processes: one event per key of a table, in `pairs` order, which Lua 5.4
-- seeds per process. The target of the cross-process digest check. A
-- project gate could not read `pairs` (the lint's GLOBAL_READ); fixtures
-- are not linted.
local Event = require("core.event")
local Gate = require("core.gate")

return Gate("fx:scatter", function()
  local t = {}
  for i = 1, 24 do t["k" .. i] = i end
  local out = {}
  for k, v in pairs(t) do out[#out + 1] = Event("fx:part", { key = k, v = v }) end
  return out
end, { emits = { "fx:part" }, rejects = {} })
