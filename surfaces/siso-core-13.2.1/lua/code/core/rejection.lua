--[[
core.rejection — the table a rejected propagation raises (K5).

Contract core-api REJECTION. A rejection carries the reason and the
identities of the delivery involved, and nothing else:

  code       SISO_SELF_LOOP | SISO_CYCLE | SISO_GATE_THREW | SISO_NOT_EVENT
  message    a string; tostring(rejection) returns it
  eventId    the delivery involved (nil only for a refused entry)
  causeId    that delivery's cause (nil for the entry delivery)
  eventType  that delivery's event type (nil only for a refused entry)
  depth      SISO_CYCLE only
  cause      SISO_GATE_THREW only: exactly what the gate raised

  local Rejection = require("core.rejection")
  Rejection.selfLoop(delivery, signature)
  Rejection.cycle(delivery, gateCount)
  Rejection.gateThrew(delivery, signature, cause)
  Rejection.notEvent(delivery, signature, what)   delivery nil: refused entry
  Rejection.is(v)

`delivery` is the stream's view of one delivery: { id, causeId, type,
depth }. Each builder makes a fresh table holding exactly the fields its
code carries; nothing here is kept between calls.

getmetatable on a rejection answers the string "siso.rejection" (core
13.0.0), never the module's metatable, so nothing a holder of a
rejection writes reaches another rejection (13.0.0-rc1 handed out a table
every rejection shared). Rejection.is compares that string. An observer
is never shown this table: it receives freeze.plain of it (observer-seam
THE REJECTION).
]]

local MARK = "siso.rejection"

local RejectionMeta = {
  __metatable = MARK,
  __tostring = function(r) return r.message end,
}

local function build(code, message, delivery)
  local r = { code = code, message = message }
  if delivery then
    r.eventId = delivery.id
    r.causeId = delivery.causeId
    r.eventType = delivery.type
  end
  return setmetatable(r, RejectionMeta)
end

-- A thrown table with a string `message` (an Error-like value) shows
-- that message; anything else shows as tostring gives it.
local function thrownMessage(thrown)
  if type(thrown) == "table" and type(thrown.message) == "string" then
    return thrown.message
  end
  return tostring(thrown)
end

local Rejection = {}

function Rejection.selfLoop(delivery, signature)
  return build("SISO_SELF_LOOP",
    "Self-loop: gate '" .. tostring(signature) .. "' returned its own type",
    delivery)
end

function Rejection.cycle(delivery, gateCount)
  local r = build("SISO_CYCLE",
    "Cycle: '" .. tostring(delivery.type) .. "' claimed at depth " .. delivery.depth ..
    " with " .. gateCount .. " gates registered — a type repeated on this causal chain",
    delivery)
  r.depth = delivery.depth
  return r
end

function Rejection.gateThrew(delivery, signature, cause)
  local r = build("SISO_GATE_THREW",
    "Gate '" .. tostring(signature) .. "' threw: " .. thrownMessage(cause),
    delivery)
  r.cause = cause
  return r
end

function Rejection.notEvent(delivery, signature, what)
  if delivery == nil then
    return build("SISO_NOT_EVENT",
      "Not an Event: the entry door was given " .. what, nil)
  end
  return build("SISO_NOT_EVENT",
    "Not an Event: gate '" .. tostring(signature) .. "' returned " .. what ..
    " (a gate returns nil, an Event or a list of Events)",
    delivery)
end

function Rejection.is(v)
  return getmetatable(v) == MARK
end

return Rejection
