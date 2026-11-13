--[[
core.stream — Stream, the place where events propagate.

Contracts: core-api (the doors, ids, rejections) and observer-seam (what
an observer is shown). Core 13.

  local Stream = require("core.stream")
  local s = Stream()             no gates, no residue, no observers;
                                 Stream takes no options (12.0: the log
                                 option is gone, a log attaches as an
                                 observer)
  s:register(gate)               building; a duplicate signature raises
                                 the string "Signature collision: '<sig>'"
  s:emit(event)                  entry; one propagation until it settles,
                                 returns nothing, or raises a rejection
  s:takeResidue()                take; → the residue list, stream emptied
  s:sampleHere()                 sample; → { residue = <the sampler's own
                                 frozen copies of the events>, gateCount }
  s:attach(fn) / s:detach(fn)    observe; attaching an attached observer
                                 or detaching one that is not raises a
                                 string

Delivery is an iterative FIFO queue (breadth-first): a gate's outputs
join the back of the queue, so no call-stack depth is spent and chain
length is bounded by acyclicity alone. The order is an implementation
choice (K1), not semantics. Each queue entry carries its id, cause id and
depth beside the event (K4); none of them is ever put on the event.

Ids are per propagation: each emit numbers its deliveries 1, 2, … and
keeps the counter in a local, so nothing is counted across propagations
and no id outlives the propagation except in a rejection or an
observer's batch.

A gate's return is judged before anything it returned is queued:
  nil              consumed
  an Event         one follow-up
  a list           keys exactly 1..n, each an Event, judged in list
                   order; the first member that is not an Event
                   (SISO_NOT_EVENT) or is the gate's own type
                   (SISO_SELF_LOOP) decides; holes and named keys are
                   SISO_NOT_EVENT
  anything else    SISO_NOT_EVENT
Nothing a gate returns is dropped or repaired (Drift 7): it is carried
or the propagation is rejected. A frozen list (one a gate read from its
event's data) is judged through its read surface like any list.

Read-only events (R3, core.freeze): since core 13 Event freezes what it
builds, so an event is read-only before any stream sees it. The stream
freezes each event a gate returns when it is queued, after its return
was judged: an event already frozen (every event Event built, an event
carried in data) passes through untouched, keeping its identity, and a
table that only looks like an event (one whose getmetatable answers
"siso.event" by hand) is frozen there. So every event a gate receives, a
sample or a take hands out, or an observer's record carries the data of,
is read-only with all its data. Freezing copies each table once (see
core.freeze); its cost grows with the size of the event, never with
history.

Ownership (core-api OWNERSHIP, observer-seam RECORD data and THE
REJECTION): the stream never holds an object the outside still holds,
and never hands out one it still holds. Entry takes the stream's own
copy of the event (freeze.copy, core 13.0.0), so nothing the entering
code keeps — the event it built, residue it took and enters again —
reaches the residue, `rawset` included. A take hands the residue over.
A sample's residue holds freeze.copy of each event, each observer's
records carry freeze.copy of the data, and each observer's rejection is
freeze.plain of the one raised, one copy per observer; so a `rawset` on
a sample, a record or a rejection copy reaches neither the stream's
residue, the raised rejection, a later sample nor another observer.
Inside the propagation nothing is copied: a gate shares its input with
the stream (R3). No copy is made for observers when none is attached. A
copy's cost grows with what it copies (the entry, the residue sampled,
the deliveries shown), never with history.

Stateless through the doors (core-api STATELESS THROUGH THE EVENT, core
13.0.0): nothing reachable from an event, a sample, a take or an
observer's batch is a table the core holds or shares. Events answer
getmetatable with "siso.event", plain data with false, an observer's
rejection with false at every depth; a proxy's view, if reached through
a captured freeze key, is that one table's and shares nothing (R3,
core.freeze). The stream's own tables
(gates, residue list, observers, queue) are never placed in anything
that leaves.

Rejections (K5, core.rejection) commit nothing: residue is built in a
local list and joins the stream's only when the propagation settles, and
the propagation's ids live in locals. The stream is exactly as it was
before the entry.

Observers (observer-seam): the observers attached when the entry arrives
are each called once, in protected mode, after the propagation ended —
settled: after the residue is committed; rejected: just before the
rejection is raised — each with its own batch and record tables, its
own copies of the data and, when rejected, its own read-only plain copy
of the rejection (freeze.plain: own fields at every depth, `cause`
included, no metatable tables, string marks kept; THE REJECTION, core
13.0.0). The entering code
receives the very table raised, `cause` included: nothing done to an
observer's copy, `rawset` included, reaches it. Every observer's batch
is built, each under its own protection, before any observer is called:
a copy that cannot be built fails that observer alone, emit raises or
returns exactly as with no observer, and nothing an observer does
(taking the residue and writing to it included) reaches another's
batch. An entry too deep to copy is refused as SISO_NOT_EVENT. Records are collected only when an
observer is attached; collecting them changes nothing the propagation
does.
]]

local Event = require("core.event")
local Rejection = require("core.rejection")
local freeze = require("core.freeze")

-- A short name for a value that is not an Event, for a rejection message.
local function describe(v)
  if v == nil then return "nil" end
  local t = type(v)
  if t == "table" then return "a table that is not an Event" end
  if t == "boolean" or t == "number" then return "the " .. t .. " " .. tostring(v) end
  return "a " .. t
end

--[[
judge(result, signature, delivery) → outputs (a list of Events, possibly
empty), or nil and the rejection that the return decides.
]]
local function judge(result, signature, delivery)
  if result == nil then return {} end
  if Event.is(result) then
    if result.type == signature then return nil, Rejection.selfLoop(delivery, signature) end
    return { result }
  end
  if type(result) ~= "table" then
    return nil, Rejection.notEvent(delivery, signature, describe(result))
  end
  -- A frozen list (a gate may return one it read from its event's data)
  -- is read through its read surface; any other table is read raw.
  local frozen = freeze.isFrozen(result)
  local n = 0
  while true do
    local member
    if frozen then member = result[n + 1] else member = rawget(result, n + 1) end
    if member == nil then break end
    n = n + 1
    if not Event.is(member) then
      return nil, Rejection.notEvent(delivery, signature,
        "a list whose member " .. n .. " is " .. describe(member))
    end
    if member.type == signature then return nil, Rejection.selfLoop(delivery, signature) end
  end
  local keys = 0
  if frozen then
    for _ in pairs(result) do keys = keys + 1 end
  else
    for _ in next, result do keys = keys + 1 end
  end
  if keys ~= n then
    return nil, Rejection.notEvent(delivery, signature,
      "a table whose keys are not exactly 1.." .. n .. " (a hole or a named key)")
  end
  return result
end

-- One observer's call: its own batch and record tables, its own frozen
-- copy of each record's data and its own plain copy of the rejection
-- (OWNERSHIP, THE REJECTION), all built here, inside that observer's
-- protected call. One memo per observer, so data passed on along a chain
-- is copied once for that observer and shared with nothing else; the
-- rejection's copy has a memo of its own (a rejection shares nothing with
-- event data).
local function batchFor(outcome, rejection, records)
  local own, memo = {}, {}
  for i, r in ipairs(records) do
    own[i] = {
      eventId = r.eventId,
      causeId = r.causeId,
      eventType = r.eventType,
      claimed = r.claimed,
      data = freeze.copy(r.data, memo),
    }
  end
  local seen = nil
  if rejection ~= nil then
    -- the copy is plain data, not a rejection: its own fields only, so
    -- it answers false (string marks inside, such as an array's, stay)
    local fields = {}
    for k, v in pairs(rejection) do fields[k] = v end
    seen = freeze.plain(fields)
  end
  return { outcome = outcome, rejection = seen, records = own }
end

-- Each observer is called in protected mode: building its batch or
-- running it may fail, and that observer fails alone.
-- Every observer's batch is built before any observer is called
-- (observer-seam ISOLATION), each under protection: a batch that cannot
-- be built fails its observer alone, and nothing an observer does —
-- taking the residue and writing to it included — reaches a batch
-- already built for another.
local function notify(observers, outcome, rejection, records)
  local batches = {}
  for i = 1, #observers do
    local ok, batch = pcall(batchFor, outcome, rejection, records)
    if ok then batches[i] = batch end
  end
  for i, observer in ipairs(observers) do
    if batches[i] ~= nil then pcall(observer, batches[i]) end
  end
end

local function reject(observers, records, rejection)
  notify(observers, "rejected", rejection, records)
  error(rejection, 0)
end

local S = {}
S.__index = S

-- Stream() takes no options; any argument is ignored.
local function Stream()
  return setmetatable({
    gates = {},
    gateCount = 0,
    residue = {},
    observers = {},
  }, S)
end

function S:register(gate)
  if self.gates[gate.signature] ~= nil then
    error("Signature collision: '" .. tostring(gate.signature) .. "'", 0)
  end
  self.gates[gate.signature] = gate
  self.gateCount = self.gateCount + 1
end

function S:emit(event)
  local observers = {}
  for i, o in ipairs(self.observers) do observers[i] = o end
  local records = {}
  local keepRecords = #observers > 0

  if not Event.is(event) then
    reject(observers, records, Rejection.notEvent(nil, nil, describe(event)))
  end

  -- the stream's own copy: nothing the entering code keeps reaches it.
  -- Copying recurses, so an event deep enough for the substrate's stack
  -- cannot be copied: it is refused as a rejection, never raised raw.
  local copied, entered = pcall(freeze.copy, event)
  if not copied then
    reject(observers, records, Rejection.notEvent(nil, nil, "an Event too deep to copy"))
  end
  event = entered
  local gates, gateCount = self.gates, self.gateCount
  local queue = { { event = event, causeId = nil, depth = 1 } }
  local head, tail = 1, 1
  local lastId = 0
  local settled = {}

  while head <= tail do
    local delivery = queue[head]
    queue[head] = nil
    head = head + 1
    local current = delivery.event
    lastId = lastId + 1
    delivery.id = lastId
    delivery.type = current.type
    local gate = gates[current.type]

    if keepRecords then
      records[lastId] = {
        eventId = lastId,
        causeId = delivery.causeId,
        eventType = current.type,
        claimed = gate ~= nil,
        data = current.data,
      }
    end

    if not gate then
      settled[#settled + 1] = current
    else
      if delivery.depth > gateCount then
        reject(observers, records, Rejection.cycle(delivery, gateCount))
      end
      local ok, result = pcall(gate.transform, current)
      if not ok then
        reject(observers, records, Rejection.gateThrew(delivery, gate.signature, result))
      end
      local outputs, rejection = judge(result, gate.signature, delivery)
      if rejection then reject(observers, records, rejection) end
      for i = 1, #outputs do
        tail = tail + 1
        queue[tail] = { event = freeze(outputs[i]), causeId = lastId, depth = delivery.depth + 1 }
      end
    end
  end

  local residue = self.residue
  for i = 1, #settled do residue[#residue + 1] = settled[i] end
  notify(observers, "settled", nil, records)
end

-- The sampler's own frozen copies of the residue's events (OWNERSHIP);
-- one memo per sample, so the copies share nothing with the stream or
-- with another sample.
function S:sampleHere()
  local copy, memo = {}, {}
  for i, e in ipairs(self.residue) do copy[i] = freeze.copy(e, memo) end
  return { residue = copy, gateCount = self.gateCount }
end

function S:takeResidue()
  local taken = self.residue
  self.residue = {}
  return taken
end

function S:attach(observer)
  if type(observer) ~= "function" then
    error("attach: an observer is a function, got " .. type(observer), 0)
  end
  local list = self.observers
  for i = 1, #list do
    if rawequal(list[i], observer) then error("attach: this observer is already attached", 0) end
  end
  list[#list + 1] = observer
end

function S:detach(observer)
  local list = self.observers
  for i = 1, #list do
    if rawequal(list[i], observer) then
      table.remove(list, i)
      return
    end
  end
  error("detach: this observer is not attached", 0)
end

return Stream
