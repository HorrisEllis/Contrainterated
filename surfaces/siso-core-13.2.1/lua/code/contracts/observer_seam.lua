--[[
contract: observer-seam — what the stream shows an observer (core 13).

Between: stream -> observer. Only a contracts phase changes this file.

THE DOOR
  stream:attach(observer) and stream:detach(observer) (core-api). An
  observer is a function. Attaching or detaching changes nothing the
  stream does: not the residue, not a rejection, not what a gate receives.

ONE CALL PER PROPAGATION, AFTER IT HAS ENDED
  The stream calls each attached observer once per propagation, with a
  batch, when the propagation is over:
    settled   after its residue is committed;
    rejected  after nothing was committed, just before the rejection is
              raised.
  No observer runs while a propagation is in progress, so an observer
  that calls a door (emit, register, takeResidue, attach, …) makes the
  next crossing; it cannot touch the propagation it is shown.
  A refused entry (SISO_NOT_EVENT at the entry door) is a propagation
  with no deliveries: its batch has no records.

THE BATCH
  {
    outcome   = "settled" | "rejected",
    rejection = the observer's own copy of the rejection emit raises
                (rejected only, else nil; see THE REJECTION),
    records   = { record, … }   the deliveries, in delivery order,
  }
  and no other field. Each observer receives its own batch and record
  tables, so one observer cannot change what another is shown.

THE REJECTION (core 13; 12 handed every observer the raised table itself)
  A plain, read-only copy of the rejection emit raises, the observer's
  own (core 13.0.0): the same fields with equal values. A `cause` that is
  a table is copied as its own fields (what `pairs` yields), at every
  depth, without its metatable: an error object's methods and inherited
  fields are not carried; the rejection's `message` carries its
  rendering. getmetatable answers false or a string on the copy and on
  every table in it, never a table, so nothing the core holds is
  reachable from it: a string mark is kept (an array in a `cause` stays
  an array), and an immutable "readonly:" sentinel passes through as
  itself. Assignment
  raises, and nothing done to it, `rawset` included, reaches the
  rejection the entering code receives, what the gate raised, or another
  observer's copy (core-api OWNERSHIP). tostring is not promised on the
  copy; read its `message`.

A RECORD
  { eventId, causeId, eventType, claimed, data } and no other field:
    eventId    1, 2, … in delivery order (core-api IDS), contiguous
    causeId    the eventId of the delivery whose gate returned this event;
               nil for the entry delivery
    eventType  the event's type
    claimed    true when a gate claimed the event, false when it settled
               as residue
    data       a frozen copy of the event's data as the gate received it,
               the observer's own: nothing done to it, `rawset` included,
               reaches the stream's residue or another observer
               (core-api OWNERSHIP)
  In a rejected propagation the records end with the delivery the
  rejection names: the one whose gate returned its own type or a
  non-Event (SISO_SELF_LOOP, SISO_NOT_EVENT), the one claimed too deep
  (SISO_CYCLE), or the one whose gate raised (SISO_GATE_THREW). A
  returned event that caused the rejection is never delivered, so it has
  no record.

ISOLATION
  An observer that raises is the observer's own failure. The stream calls
  observers in protected mode: emit still returns or raises exactly as
  it would with no observer, and every other observer still receives its
  batch. Building an observer's batch (its copies included) is part of
  that observer's call: if it fails, that observer fails alone. Every
  observer's batch is built before any observer is called, so nothing an
  observer does — taking the residue and writing to what it took
  included — reaches what another observer is shown. The order in which
  observers are called carries no meaning.

What an observer stores, numbers or labels is its own (observer-side
state, R2). The stream holds no stream id, lineage or level.

rejection_copy_problem(copy, raised) judges one observer's rejection
against the one raised (any runner may use it).
check.seam(core) and check.ownership(core) run this contract against
`core = { Event, Gate, Stream }` and return a list of problems.
]]

local M = {}

local BATCH_FIELDS = { outcome = true, rejection = true, records = true }
local RECORD_FIELDS = { eventId = true, causeId = true, eventType = true, claimed = true, data = true }

--[[
batch_problems(batch) → list of problems: the batch's own shape, and the
records' ids and causes consistent with one propagation.
]]
function M.batch_problems(batch)
  local p = {}
  if type(batch) ~= "table" then return { "a batch is a table, got " .. type(batch) } end
  for k in pairs(batch) do
    if not BATCH_FIELDS[k] then p[#p + 1] = "batch: unexpected field '" .. tostring(k) .. "'" end
  end
  if batch.outcome ~= "settled" and batch.outcome ~= "rejected" then
    p[#p + 1] = "batch: outcome is " .. tostring(batch.outcome)
  end
  if batch.outcome == "settled" and batch.rejection ~= nil then p[#p + 1] = "batch: a settled batch carries a rejection" end
  if batch.outcome == "rejected" and type(batch.rejection) ~= "table" then p[#p + 1] = "batch: a rejected batch carries no rejection" end
  if type(batch.records) ~= "table" then
    p[#p + 1] = "batch: records is not a list"
    return p
  end
  local n = 0
  for _ in pairs(batch.records) do n = n + 1 end
  if n ~= #batch.records then p[#p + 1] = "batch: records is not a list (holes or named keys)" end
  for i, r in ipairs(batch.records) do
    local where = "record " .. i
    if type(r) ~= "table" then
      p[#p + 1] = where .. ": not a table"
    else
      for k in pairs(r) do
        if not RECORD_FIELDS[k] then p[#p + 1] = where .. ": unexpected field '" .. tostring(k) .. "'" end
      end
      if r.eventId ~= i then p[#p + 1] = where .. ": eventId is " .. tostring(r.eventId) .. ", expected " .. i end
      if i == 1 then
        if r.causeId ~= nil then p[#p + 1] = where .. ": the entry delivery has a cause" end
      elseif math.type(r.causeId) ~= "integer" or r.causeId < 1 or r.causeId >= i then
        p[#p + 1] = where .. ": causeId " .. tostring(r.causeId) .. " is not an earlier delivery"
      elseif batch.records[r.causeId].claimed ~= true then
        p[#p + 1] = where .. ": its cause was not claimed by a gate"
      end
      if type(r.claimed) ~= "boolean" then p[#p + 1] = where .. ": claimed is not a boolean" end
      if type(r.data) ~= "table" then p[#p + 1] = where .. ": data is not a table" end
    end
  end
  if batch.outcome == "rejected" and type(batch.rejection) == "table" then
    local last = batch.records[#batch.records]
    if batch.rejection.eventId == nil then
      if #batch.records ~= 0 then p[#p + 1] = "batch: a refused entry has records" end
    elseif not last or last.eventId ~= batch.rejection.eventId then
      p[#p + 1] = "batch: the records do not end with the delivery the rejection names"
    end
  end
  return p
end

-- a and b equal by value: tables through `pairs` and indexing, both
-- ways, anything else by ==
local function same(a, b, depth)
  if type(a) ~= "table" or type(b) ~= "table" then return a == b end
  if rawequal(a, b) then return true end
  if (depth or 0) > 100 then return false end
  for k, x in pairs(a) do
    if not same(x, b[k], (depth or 0) + 1) then return false end
  end
  for k in pairs(b) do
    if a[k] == nil then return false end
  end
  return true
end

--[[
rejection_copy_problem(copy, raised) → nil when `copy` is an observer's
own copy of the rejection `raised` (THE REJECTION), else a string naming
what is wrong: the same table (at any depth), a table that answers
getmetatable with anything but false or a string, a field missing, added or unequal (a table `cause` compared
by its own fields), or a copy that accepts assignment.
]]
local function isSentinel(v)
  local mt = getmetatable(v)
  return type(mt) == "string" and mt:sub(1, 9) == "readonly:"
end

-- the first table in v (v included) that answers getmetatable with
-- anything but false, or that is the same table as one in `raisedTables`;
-- immutable sentinels are skipped
local function notPlainCopy(v, raisedTables, seen, path)
  if type(v) ~= "table" or isSentinel(v) then return nil end
  if seen[v] then return nil end
  seen[v] = true
  if raisedTables[v] then return path .. " is the raised table itself, not a copy" end
  local mt = getmetatable(v)
  if mt ~= false and type(mt) ~= "string" then
    return path .. " answers getmetatable with " .. tostring(mt) .. ", not false or a string"
  end
  for k, x in pairs(v) do
    local why = notPlainCopy(x, raisedTables, seen, path .. "." .. tostring(k))
    if why then return why end
  end
  return nil
end

local function tablesIn(v, into)
  if type(v) ~= "table" or into[v] then return into end
  into[v] = true
  for _, x in pairs(v) do tablesIn(x, into) end
  return into
end

function M.rejection_copy_problem(copy, raised)
  if type(copy) ~= "table" then return "the batch's rejection is not a table" end
  if rawequal(copy, raised) then return "the batch's rejection is the very table raised, not the observer's copy" end
  local plain = notPlainCopy(copy, tablesIn(raised, {}), {}, "the batch's rejection")
  if plain then return plain end
  for k, v in pairs(raised) do
    if not same(v, copy[k]) then return "the batch's rejection differs from the one raised at '" .. tostring(k) .. "'" end
  end
  for k in pairs(copy) do
    if raised[k] == nil then return "the batch's rejection has a field the raised one lacks: '" .. tostring(k) .. "'" end
  end
  if pcall(function() copy.code = copy.code end) then return "the batch's rejection accepts assignment" end
  return nil
end

local check = {}
M.check = check

function check.seam(core)
  local p = {}
  local Event, Gate, Stream = core.Event, core.Gate, core.Stream
  local function fail(s) p[#p + 1] = s end
  local function addAll(label, list) for _, s in ipairs(list) do fail(label .. ": " .. s) end end
  local function recorder(into) return function(b) into[#into + 1] = b end end
  local function types(b)
    local t = {}
    for i, r in ipairs(b.records) do t[i] = r.eventType .. (r.claimed and "+" or "-") .. "<" .. tostring(r.causeId) end
    return table.concat(t, " ")
  end

  local ok, err = pcall(function()
    -- a settled propagation: one batch, after the residue is committed
    local s = Stream()
    local got, residueSeen = {}, nil
    s:register(Gate("root", function() return { Event("a"), Event("b") } end))
    s:register(Gate("a", function() return Event("a1") end))
    s:attach(function(b) got[#got + 1] = b; residueSeen = #s:sampleHere().residue end)
    s:emit(Event("root", { k = 1 }))
    if #got ~= 1 then fail("a settled propagation gave " .. #got .. " batches") else
      local b = got[1]
      addAll("settled", M.batch_problems(b))
      if b.outcome ~= "settled" then fail("settled: outcome " .. tostring(b.outcome)) end
      if #b.records ~= 4 then fail("settled: " .. #b.records .. " records, expected 4: " .. types(b)) end
      if b.records[1].data.k ~= 1 then fail("settled: the entry record does not carry its data") end
      if residueSeen ~= 2 then fail("settled: the observer ran before the residue was committed") end
    end
    -- the next propagation on the same stream numbers its own deliveries
    s:emit(Event("root"))
    if #got == 2 then addAll("second propagation", M.batch_problems(got[2])) end

    -- a rejected propagation: the batch carries the observer's own copy
    -- of the rejection raised (THE REJECTION)
    local r = Stream()
    local rb = {}
    r:register(Gate("a", function() return Event("b") end))
    r:register(Gate("b", function() error("boom") end))
    r:attach(recorder(rb))
    local okE, raised = pcall(r.emit, r, Event("a"))
    if okE then fail("rejected: the gate's raise did not reject") end
    if #rb ~= 1 then fail("rejected: " .. #rb .. " batches") else
      addAll("rejected", M.batch_problems(rb[1]))
      local why = M.rejection_copy_problem(rb[1].rejection, raised)
      if why then fail("rejected: " .. why) end
      if #rb[1].records ~= 2 then fail("rejected: " .. #rb[1].records .. " records, expected 2") end
    end

    -- a refused entry: a batch with no records
    local e = Stream()
    local eb = {}
    e:attach(recorder(eb))
    pcall(e.emit, e, "not an event")
    if #eb ~= 1 or #eb[1].records ~= 0 or eb[1].outcome ~= "rejected" then
      fail("refused entry: expected one rejected batch with no records")
    end

    -- an observer that raises changes nothing, and others still see the batch
    local t = Stream()
    local tb = {}
    t:register(Gate("x", function() return Event("y") end))
    t:attach(function() error("observer failure") end)
    t:attach(recorder(tb))
    local okT, errT = pcall(t.emit, t, Event("x"))
    if not okT then fail("a raising observer made emit raise: " .. tostring(errT)) end
    if #t:sampleHere().residue ~= 1 then fail("a raising observer changed the residue") end
    if #tb ~= 1 then fail("a raising observer kept another from its batch") end

    -- an observer that calls doors makes the next crossing; the propagation it saw is untouched
    local u = Stream()
    local fired = false
    u:register(Gate("a", function() return Event("b") end))
    u:attach(function(b)
      if fired then return end
      fired = true
      u:register(Gate("b", function() return Event("late") end))
      u:emit(Event("other"))
    end)
    u:emit(Event("a"))
    local types2 = {}
    for _, ev in ipairs(u:takeResidue()) do types2[#types2 + 1] = ev.type end
    table.sort(types2)
    if table.concat(types2, ",") ~= "b,other" then
      fail("an observer's crossings reached the propagation it was shown: residue " .. table.concat(types2, ","))
    end

    -- each observer gets its own batch
    local v = Stream()
    local seen2
    v:attach(function(b) b.records[1] = nil; b.outcome = "x" end)
    v:attach(function(b) seen2 = b end)
    v:emit(Event("q"))
    if not seen2 or seen2.outcome ~= "settled" or #seen2.records ~= 1 then
      fail("one observer's writes to its batch reached another observer")
    end

    -- detach: no more batches; misuse raises
    local w = Stream()
    local wb = {}
    local f = recorder(wb)
    w:attach(f)
    if pcall(w.attach, w, f) then fail("attaching an attached observer did not raise") end
    w:detach(f)
    w:emit(Event("z"))
    if #wb ~= 0 then fail("a detached observer was called") end
    if pcall(w.detach, w, f) then fail("detaching an observer that is not attached did not raise") end

    -- attaching changes nothing: same entry, same residue and rejection
    local function run(withObserver)
      local x = Stream()
      x:register(Gate("a", function() return { Event("b"), Event("c") } end))
      x:register(Gate("b", function() return Event("a") end))
      if withObserver then x:attach(function() end) end
      local okR, rr = pcall(x.emit, x, Event("a"))
      return okR and "settled" or (rr.code .. "@" .. tostring(rr.eventId) .. "/" .. tostring(rr.depth))
    end
    if run(false) ~= run(true) then fail("attaching an observer changed a rejection") end
  end)
  if not ok then fail("raised " .. tostring(err)) end
  return p
end

function check.ownership(core)
  local p = {}
  local Event, Stream = core.Event, core.Stream
  local function fail(s) p[#p + 1] = s end
  local ok, err = pcall(function()
    -- both attach orders, since the order observers are called in is free
    for _, writerFirst in ipairs({ true, false }) do
      local s = Stream()
      local seen
      local function writer(b)
        local d = b.records[1].data
        if pcall(function() d.v = 5 end) then fail("an observer's record data accepts assignment") end
        rawset(d, "v", 99)
        rawset(d.n, 1, 9)
      end
      local function reader(b) seen = b.records[1].data end
      if writerFirst then s:attach(writer); s:attach(reader) else s:attach(reader); s:attach(writer) end
      s:emit(Event("x", { v = 1, n = { 1 } }))
      if seen.v ~= 1 or seen.n[1] ~= 1 then fail("an observer's rawset reached another observer's record") end
      local r = s:takeResidue()[1]
      if r.data.v ~= 1 or r.data.n[1] ~= 1 then fail("an observer's rawset reached the residue") end
    end

    -- the rejection (THE REJECTION, core 13): each observer's copy is its
    -- own; nothing done to it reaches the raised one or another observer
    for _, writerFirst in ipairs({ true, false }) do
      local s = Stream()
      local thrown = { "the gate's own", nested = { 1 } }
      s:register(core.Gate("a", function() error(thrown) end))
      local seen
      local function writer(b)
        local r = b.rejection
        if pcall(function() r.code = "CHANGED" end) then fail("an observer's rejection accepts assignment") end
        rawset(r, "code", "CHANGED")
        rawset(r, "eventId", 99)
        if type(r.cause) == "table" then
          rawset(r.cause, 1, "CHANGED")
          if type(r.cause.nested) == "table" then rawset(r.cause.nested, 1, 9) end
        end
      end
      local function reader(b) seen = b.rejection end
      if writerFirst then s:attach(writer); s:attach(reader) else s:attach(reader); s:attach(writer) end
      local okE, raised = pcall(s.emit, s, Event("a"))
      if okE then fail("rejection ownership: the gate's raise did not reject")
      else
        if raised.code ~= "SISO_GATE_THREW" or raised.eventId ~= 1 then
          fail("an observer's rawset reached the rejection emit raised")
        end
        if not rawequal(raised.cause, thrown) or thrown[1] ~= "the gate's own" or thrown.nested[1] ~= 1 then
          fail("an observer's rawset reached what the gate raised")
        end
        if type(seen) ~= "table" or seen.code ~= "SISO_GATE_THREW" or seen.eventId ~= 1
          or type(seen.cause) ~= "table" or seen.cause[1] ~= "the gate's own" or seen.cause.nested[1] ~= 1 then
          fail("an observer's rawset on its rejection reached another observer's")
        end
      end
    end

    -- observers are shown batches built before any is called: one that
    -- takes the residue and writes to it reaches no other (both orders)
    for _, takerFirst in ipairs({ true, false }) do
      local s = Stream()
      local seenV
      local function taker()
        for _, e in ipairs(s:takeResidue()) do rawset(e.data, "v", "TAKER WROTE") end
      end
      local function reader(b) seenV = b.records[1].data.v end
      if takerFirst then s:attach(taker); s:attach(reader) else s:attach(reader); s:attach(taker) end
      s:emit(Event("x", { v = 1 }))
      if seenV ~= 1 then
        fail("an observer that took the residue and wrote to it reached another observer's records (" ..
          (takerFirst and "taker first" or "reader first") .. ")")
      end
    end

    -- nothing the core holds is reachable from an observer's rejection
    -- (core 13.0.0): an observer that writes to whatever getmetatable
    -- answers changes neither the host's rejection nor a later stream's
    local function tamper(b)
      local r = b.rejection
      for _, t in ipairs({ r, type(r.cause) == "table" and r.cause or nil }) do
        pcall(function()
          local mt = getmetatable(t)
          mt.__tostring = function() return "OBSERVER WROTE" end
          mt.__index = function() return "OBSERVER WROTE" end
        end)
      end
    end
    local Err = { __index = { kind = "io" } }
    local m = Stream()
    m:register(core.Gate("a", function() error(setmetatable({ msg = "m" }, Err)) end))
    m:attach(tamper)
    local _, raisedM = pcall(m.emit, m, Event("a"))
    if type(raisedM) ~= "table" or tostring(raisedM) ~= raisedM.message then
      fail("an observer reached the host's rejection through its copy's metatable")
    elseif raisedM.cause.kind ~= "io" or raisedM.cause.missing ~= nil then
      fail("an observer reached what the gate raised through its copy's metatable")
    end
    local later = Stream()
    later:register(core.Gate("a", function() error("boom") end))
    local _, raisedL = pcall(later.emit, later, Event("a"))
    if type(raisedL) ~= "table" or tostring(raisedL) ~= raisedL.message or raisedL.missing ~= nil then
      fail("an observer's write through its copy's metatable reached a later stream's rejection")
    end
    local m2 = Stream()
    m2:register(core.Gate("a", function() error(setmetatable({ msg = "m" }, Err)) end))
    local shown
    m2:attach(function(b) shown = b.rejection end)
    local _, raised2 = pcall(m2.emit, m2, Event("a"))
    local why = M.rejection_copy_problem(shown, raised2)
    if why then fail("an error object raised by a gate: " .. why) end
    if type(shown) == "table" and type(shown.cause) == "table" and shown.cause.msg ~= "m" then
      fail("an observer's copy lost the cause's own field")
    end
  end)
  if not ok then fail("raised " .. tostring(err)) end
  return p
end

return M
