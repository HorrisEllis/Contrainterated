--[[
observer.streamlog — StreamLog, the audit trail, on the core 12 observer seam.

A log is an observer (SISO principles, "The log — not a primitive"). The
stream shows it one batch per propagation, after the propagation has ended
(code/contracts/observer_seam.lua):
  { outcome = "settled" | "rejected", rejection = <raised table> | nil,
    records = { { eventId, causeId, eventType, claimed, data }, … } }
The log stores what it is shown and reads nothing back into any stream.
Nothing here is consulted by a gate, a sample or any running part.

Everything the log keeps is its own (observer-side): entries, seq,
timestamps, level, stream labels, lineage and propagation numbers. There
is no module-global state: two logs share nothing. It requires nothing
from code/core/.

API
  local StreamLog = require("observer.streamlog")

  local log = StreamLog(level)
      level is "OFF" | "EVENTS" | "DEEP" | "DATA"; nil means "EVENTS".
      Any other value raises "StreamLog: unknown level …" (X2).

  local obs = log:observer(label, parentLabel)
      → the function to pass to stream:attach(obs) (and stream:detach).
      label       a non-empty string naming the observed stream in this log;
                  a label already used in this log raises.
      parentLabel optional; the label of the stream this one is a
                  sub-stream of. It must already be a label of this log,
                  else it raises (lineage names only streams the log knows).
      Each call of obs is one propagation of that stream. The observer
      numbers them 1, 2, … (its own propagation number, counted for every
      batch it is shown, whatever the level).

  log:setLevel(level)   change the level; unknown levels raise (X2). The
                        following batch respects it.
  log:level()           → the current level.
  log:sample()          → { level, count, entries } where entries is a copy
                          (new list, new entry tables); nothing done to a
                          sample reaches the log. `data`, when present, is
                          the read-only table the stream showed.
  log:clear()           drop all entries and reset seq to 0. Labels and
                        propagation numbers are kept, so a propagation is
                        never numbered twice.

LEVELS (each includes everything below)
  OFF     nothing is recorded
  EVENTS  per delivery: seq (0, 1, … per log, in the order the log was
          shown records), time (os.time(), seconds), propagation,
          outcome ("settled" | "rejected"), eventId, causeId (nil for the
          entry delivery), type, claimed (boolean, as the seam shows it)
  DEEP    + streamLabel, and parentLabel when the stream has a parent
  DATA    + data

IDENTITY AND CAUSALITY
  The stream numbers deliveries 1, 2, … afresh in every propagation, so an
  eventId alone names nothing. Within one stream (label) a delivery is
  (propagation, eventId); across streams sharing a log it is
  (streamLabel, propagation, eventId), with streamLabel recorded at DEEP.
  causeId names a delivery of the same propagation: walking causeId links
  within one (streamLabel, propagation) reconstructs a causal chain,
  whatever order entries were written in. A rejected propagation's
  entries end with the delivery the rejection names (rejection.eventId).

Differences from 11.1 (legacy/js/code/src/StreamLog.js): the stream no
longer calls record() per delivery but hands a batch per propagation; the
module-global streamIdCounter and nextStreamId() are gone (labels are
chosen by whoever attaches); claimed is a boolean, not a signature
(signature == type, one gate per type); time is in seconds; an unknown
level raises instead of acting as OFF.
]]

local LEVELS = { OFF = 0, EVENTS = 1, DEEP = 2, DATA = 3 }

local function rank(level)
  local r = type(level) == "string" and LEVELS[level] or nil
  if r == nil then
    error("StreamLog: unknown level '" .. tostring(level) .. "' (OFF, EVENTS, DEEP, DATA)", 3)
  end
  return r
end

local function copyEntry(e)
  return {
    seq = e.seq, time = e.time, propagation = e.propagation, outcome = e.outcome,
    eventId = e.eventId, causeId = e.causeId, type = e.type, claimed = e.claimed,
    streamLabel = e.streamLabel, parentLabel = e.parentLabel, data = e.data,
  }
end

local function StreamLog(initial)
  if initial == nil then initial = "EVENTS" end
  local level, lvl = initial, rank(initial)
  local entries, seq = {}, 0
  local labels = {}

  local log = {}

  function log:level() return level end

  function log:setLevel(l)
    lvl = rank(l)
    level = l
  end

  function log:observer(label, parentLabel)
    if type(label) ~= "string" or label == "" then
      error("StreamLog: a stream label is a non-empty string", 2)
    end
    if labels[label] then error("StreamLog: label '" .. label .. "' is already used", 2) end
    if parentLabel ~= nil and not labels[parentLabel] then
      error("StreamLog: parent label '" .. tostring(parentLabel) .. "' is not a label of this log", 2)
    end
    labels[label] = true
    local propagation = 0
    return function(batch)
      propagation = propagation + 1
      if lvl == 0 then return end
      for _, r in ipairs(batch.records) do
        local e = {
          seq = seq, time = os.time(), propagation = propagation, outcome = batch.outcome,
          eventId = r.eventId, causeId = r.causeId, type = r.eventType, claimed = r.claimed,
        }
        if lvl >= 2 then
          e.streamLabel = label
          e.parentLabel = parentLabel
        end
        if lvl >= 3 then e.data = r.data end
        seq = seq + 1
        entries[#entries + 1] = e
      end
    end
  end

  function log:sample()
    local copy = {}
    for i, e in ipairs(entries) do copy[i] = copyEntry(e) end
    return { level = level, count = #entries, entries = copy }
  end

  function log:clear()
    entries, seq = {}, 0
  end

  return log
end

return StreamLog
