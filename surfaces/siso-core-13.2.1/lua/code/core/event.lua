--[[
core.event — Event, a datum flowing through the stream.

Contract core-api EVENT / READ-ONLY / STATELESS THROUGH THE EVENT
(core 13.0.0). Port of core 11.1 legacy/js/code/src/Event.js.

  local Event = require("core.event")
  Event(type, data) → a read-only event { type = type, data = data }
                      (data defaults to {})
  Event.is(v)       → true only for a value built by Event:
                      getmetatable(v) == "siso.event"

An event is a type and data, nothing else. Extra arguments attach
nothing. Created, never modified by delivery: the stream's delivery
metadata (id, cause, depth) rides beside the event in the queue, never
on it.

Read-only from construction (core 13): Event freezes what it builds
(core.freeze), with its data and every table reachable from it, wherever
it is called (a gate, a boundary layer, a test). Freezing builds its view
of the data once, so the data is taken as it stands: a write the caller
makes afterwards to the table it passed does not reach the event. Data
already read-only (a frozen table, an immutable sentinel) is kept as it
is, so an event or data passed on unchanged keeps its identity.

Stateless through the event (core 13.0.0): getmetatable on an event
answers the string "siso.event", never a table, so no module table is
reachable from an event (12.0.1 and 13.0.0-rc1 handed out EventMeta, a
writable table every event shared: a gate could count through it from
one stream to the next). The metatable below is only the source the
freeze reads its answer from; it never leaves this module. Assigning
through the substrate's back door (`rawset`) is not refused: the
substrate concession R3.
]]

local freeze = require("core.freeze")

local MARK = "siso.event"
local EventMeta = { __metatable = MARK }

local Event = setmetatable({}, {
  __call = function(_, eventType, data)
    if data == nil then data = {} end
    return freeze(setmetatable({ type = eventType, data = data }, EventMeta))
  end,
})

function Event.is(v)
  return getmetatable(v) == MARK
end

return Event
