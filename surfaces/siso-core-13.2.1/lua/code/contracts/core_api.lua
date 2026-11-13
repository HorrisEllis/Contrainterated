--[[
contract: core-api — what the core hands everyone outside it (core 13).

Between: stream -> observer, kit, fixtures, and every consuming project
(jää, the pipeline, spine). Only a contracts phase changes this file.
It holds what crosses, not how the stream works.

MODULES (import root code/)
  core.event   Event
  core.gate    Gate
  core.stream  Stream

EVENT
  Event(type, data) → an event with exactly two fields, `type` and `data`,
    read-only from the moment it is built (core 13): assignment to the
    event, or to anything in its data, raises, wherever the event was
    built (a gate, a boundary layer, a test). `data` defaults to {} when
    nil. Extra arguments attach nothing. The data is taken as it stands
    when Event is called: a write the caller makes afterwards to the
    table it passed does not reach the event, so data is complete before
    its event is built. Data that is already read-only (frozen, or an
    immutable sentinel, below) is kept as it is, so an event or data
    passed on unchanged keeps its identity.
  Event.is(v) → true only for a value built by Event:
    getmetatable(v) == "siso.event".
  STATELESS THROUGH THE EVENT (core 13.0.0): nothing reachable from an
  event holds state a gate could write and later read. getmetatable on an
  event answers the string "siso.event", never a table; a frozen table
  answers what its original answered (false for none, a string for a
  mark). So through an event built from plain data a gate reaches only
  immutable values. `rawset` past a frozen table, and the substrate's own
  globals (the string metatable among them), stay the substrate
  concession R3.
  An event is plain data: `data` holds strings, numbers (integers and
  floats kept apart, finite), booleans, the null sentinel (kit.data.null)
  and tables of them, exactly what kit.data encodes as canonical JSON
  (SISO "polyglot"; the kit checks this, the stream does not).
  READ-ONLY (from stream-freeze, R3; from construction since core 13):
  every event Event builds, and so every event that enters, every event a
  gate returns, and every event a gate, a sample, a take or an observer is
  given, is read-only, with its `data` and every table reachable from it:
  assignment raises. Reads work through indexing, `#`,
  `ipairs` and `pairs`, and reading the same field twice gives the same
  value (rawequal), tables included. A frozen table answers through
  `getmetatable` what its original answered (so a data mark such as the
  array mark survives), and Event.is still recognises a frozen event.
  IMMUTABLE SENTINELS pass through freezing untouched and keep their
  identity: a table whose getmetatable answers a string beginning
  "readonly:" (kit.data.null answers "readonly:kit.data.null"). The core
  trusts the mark, so a sentinel must refuse assignment itself (its
  metatable's `__newindex`) and hold no field anyone writes: a
  "readonly:" table that accepts writes is state reachable from every
  event that carries it. The core
  12 form, a metatable table declaring `__readonly = true`, is still
  passed through, but its getmetatable hands out that writable table,
  state reachable from any event carrying it: use the string form.
  `next`, `rawget`, `rawset` and `rawlen` are not part of the read
  surface.
  OWNERSHIP: the stream never holds an object the outside still holds,
  and never hands out one it still holds. Entry takes the stream's own
  copy of the event (core 13.0.0), so nothing the entering code keeps,
  `rawset` included, reaches the residue. A take
  hands the residue over (the stream holds none of it afterwards). A
  sample's residue holds the sampler's own frozen copies of the events,
  and an observer's records carry the observer's own frozen copies of
  the data (observer-seam), so nothing done to them, `rawset` included,
  reaches the stream, a later sample or another observer. Only inside a
  propagation is an event shared: what a gate does to its input past the
  refusal (`rawset`) is the substrate concession R3.

GATE
  Gate(signature, transform, shape) → { signature, transform, shape }.
  The stream calls `gate.transform(event)` with the event as the only
  argument. `transform` returns:
    nil (or nothing)  consumed, no follow-up
    an Event          one follow-up
    a list of Events  a table whose keys are exactly 1..n, each an Event
  Anything else is a rejection (SISO_NOT_EVENT). `shape` is optional,
  a declaration for tooling ({ emits = {types…}, rejects = {types…} });
  the stream never reads it.

STREAM
  Stream() → a stream with no gates, no residue, no observers.
  Doors:
    stream:register(gate)      building. A duplicate signature raises the
                               string "Signature collision: '<sig>'".
    stream:emit(event)         entry. Runs one propagation until it
                               settles and returns nothing, or raises a
                               rejection (below). Nothing else is raised.
    stream:takeResidue()       take. → the residue as a list; the stream
                               holds none afterwards.
    stream:sampleHere()        sample. → { residue = <a list of frozen
                               copies of the residue's events (OWNERSHIP)>,
                               gateCount = <registered gates> }. Nothing
                               else (no event counter).
    stream:attach(observer)    observe. `observer` is a function; see the
    stream:detach(observer)    observer-seam contract. Attaching one that
                               is attached, or detaching one that is not,
                               raises a string.
  Residue is a multiset: its order is not part of the answer.

IDS
  Each propagation numbers its own deliveries 1, 2, … in delivery order.
  The entry delivery is 1 and has no cause (causeId nil). Nothing is
  counted across propagations, and no id survives the propagation except
  in what leaves through a door (a rejection, an observer's batch).

REJECTION
  A rejected propagation commits no residue and leaves the stream as it
  was before the entry. emit raises a table:
    code       one of the codes below
    message    a string; tostring(rejection) returns it
    eventId    the delivery involved (nil only for a refused entry)
    causeId    that delivery's cause (nil for the entry delivery)
    eventType  that delivery's event type (nil only for a refused entry)
    depth      SISO_CYCLE only: the delivery's depth on the causal chain
    cause      SISO_GATE_THREW only: exactly what the gate raised
  and no other field.
  Codes:
    SISO_SELF_LOOP   a gate returned an event of its own type. The
                     delivery is the one whose gate returned it.
    SISO_CYCLE       a claimed delivery sits deeper than the registered
                     gate count. The delivery is that one.
    SISO_GATE_THREW  a gate raised. The delivery is the one it was given.
    SISO_NOT_EVENT   emit was given a value that is not an Event, or an
                     Event the stream cannot take its own copy of (too
                     deep for the substrate) (no delivery: eventId,
                     causeId and eventType are nil), or
                     a gate returned something that is not nil, an Event
                     or a list of Events. The delivery is the one whose
                     gate returned it.
  Within one returned list, elements are judged in list order; the first
  that is not an Event or is the gate's own type decides the rejection.

check.doors(core), check.readonly(core) and check.ownership(core) run
this contract against an
implementation `core = { Event = …, Gate = …, Stream = … }` and return a
list of problems (empty when it holds). They are what the stream phases'
checks call.
]]

local M = {}

M.CODES = {
  SISO_SELF_LOOP = true,
  SISO_CYCLE = true,
  SISO_GATE_THREW = true,
  SISO_NOT_EVENT = true,
}

M.DOORS = { "register", "emit", "takeResidue", "sampleHere", "attach", "detach" }

local REJECTION_FIELDS = {
  code = true, message = true, eventId = true, causeId = true,
  eventType = true, depth = true, cause = true,
}

-- The fields a rejection may carry, by code (beyond code and message).
local EXTRA = { SISO_CYCLE = "depth", SISO_GATE_THREW = "cause" }

--[[
rejection_problems(r, want) → list of problems.
`want` (optional) pins fields: { code = …, eventId = …, causeId = …,
eventType = …, depth = …, cause = … }; a key present in `want` is
compared with ==, and `want.noDelivery = true` requires eventId, causeId
and eventType to be nil.
]]
function M.rejection_problems(r, want)
  local p = {}
  if type(r) ~= "table" then
    p[#p + 1] = "a rejection is a table, got " .. type(r)
    return p
  end
  if not M.CODES[r.code] then p[#p + 1] = "unknown code " .. tostring(r.code) end
  if type(r.message) ~= "string" then p[#p + 1] = "message is not a string" end
  if tostring(r) ~= r.message then p[#p + 1] = "tostring(rejection) is not its message" end
  for k in pairs(r) do
    if not REJECTION_FIELDS[k] then p[#p + 1] = "unexpected field '" .. tostring(k) .. "'" end
  end
  for code, field in pairs(EXTRA) do
    if r.code ~= code and r[field] ~= nil then
      p[#p + 1] = field .. " is carried only by " .. code
    end
  end
  if r.code == "SISO_CYCLE" and math.type(r.depth) ~= "integer" then
    p[#p + 1] = "SISO_CYCLE carries an integer depth"
  end
  if r.eventId ~= nil and math.type(r.eventId) ~= "integer" then
    p[#p + 1] = "eventId is not an integer"
  end
  if r.causeId ~= nil and math.type(r.causeId) ~= "integer" then
    p[#p + 1] = "causeId is not an integer"
  end
  if want then
    for _, k in ipairs({ "code", "eventId", "causeId", "eventType", "depth", "cause" }) do
      if want[k] ~= nil and r[k] ~= want[k] then
        p[#p + 1] = k .. ": expected " .. tostring(want[k]) .. ", got " .. tostring(r[k])
      end
    end
    if want.noDelivery and (r.eventId ~= nil or r.causeId ~= nil or r.eventType ~= nil) then
      p[#p + 1] = "a refused entry names no delivery"
    end
    if want.entry and r.causeId ~= nil then
      p[#p + 1] = "the entry delivery has no cause"
    end
  end
  return p
end

-- ── running the contract against an implementation ──────────────

local check = {}
M.check = check

local function add(p, label, list)
  for _, s in ipairs(list) do p[#p + 1] = label .. ": " .. s end
end

local function keysOf(t)
  local ks = {}
  for k in pairs(t) do ks[#ks + 1] = tostring(k) end
  table.sort(ks)
  return table.concat(ks, ",")
end

-- emit and catch: → nil when it settled, else what it raised
local function emitCatch(s, e)
  local ok, err = pcall(s.emit, s, e)
  if ok then return nil end
  return err
end

local function gate(core, sig, fn, shape) return core.Gate(sig, fn, shape) end

function check.doors(core)
  local p = {}
  local Event, Stream = core.Event, core.Stream
  local function fail(s) p[#p + 1] = s end
  local function guard(label, fn)
    local ok, err = pcall(fn)
    if not ok then fail(label .. ": raised " .. tostring(err)) end
  end

  guard("Event", function()
    local e = Event("t", { x = 1 })
    if keysOf(e) ~= "data,type" then fail("Event fields are " .. keysOf(e)) end
    if e.type ~= "t" or e.data.x ~= 1 then fail("Event does not hold type and data") end
    local d = Event("t")
    local dn = 0
    if type(d.data) == "table" then for _ in pairs(d.data) do dn = dn + 1 end end
    if type(d.data) ~= "table" or dn ~= 0 then fail("Event data does not default to {}") end
    if keysOf(Event("t", {}, { attempt = 1 })) ~= "data,type" then fail("an extra argument attached a field") end
    if Event.is(e) ~= true then fail("Event.is(event) is not true") end
    if Event.is({ type = "t", data = {} }) then fail("Event.is accepts a plain table") end
  end)

  guard("Gate", function()
    local fn = function() end
    local shape = { emits = {}, rejects = {} }
    local g = core.Gate("g", fn, shape)
    if g.signature ~= "g" or g.transform ~= fn or g.shape ~= shape then
      fail("Gate(signature, transform, shape) does not hold its three fields")
    end
  end)

  guard("Stream doors", function()
    local s = Stream()
    for _, d in ipairs(M.DOORS) do
      if type(s[d]) ~= "function" then fail("stream has no door " .. d) end
    end
    local sm = s:sampleHere()
    if keysOf(sm) ~= "gateCount,residue" then fail("sampleHere carries " .. keysOf(sm)) end
  end)

  guard("register", function()
    local s = Stream()
    s:register(gate(core, "a"))
    local ok, err = pcall(s.register, s, gate(core, "a"))
    if ok then fail("a duplicate signature was accepted")
    elseif not tostring(err):find("Signature collision: 'a'", 1, true) then
      fail("collision message is " .. tostring(err))
    end
    if s:sampleHere().gateCount ~= 1 then fail("gateCount is not the registered count") end
  end)

  guard("emit and take", function()
    local s = Stream()
    s:register(gate(core, "a", function(e) return Event("b", { v = e.data.v }) end))
    if select("#", s:emit(Event("a", { v = 7 }))) ~= 0 then fail("emit returned a value") end
    local smp = s:sampleHere()
    if #smp.residue ~= 1 or smp.residue[1].type ~= "b" then fail("residue after a settled chain") end
    table.remove(smp.residue)
    if #s:sampleHere().residue ~= 1 then fail("a sample's list reached the stream") end
    local taken = s:takeResidue()
    if #taken ~= 1 or taken[1].data.v ~= 7 then fail("takeResidue did not return the residue") end
    if #s:sampleHere().residue ~= 0 then fail("the stream still holds taken residue") end
  end)

  guard("returns", function()
    local s = Stream()
    s:register(gate(core, "one", function() return Event("x") end))
    s:register(gate(core, "many", function() return { Event("x"), Event("y") } end))
    s:register(gate(core, "none", function() return nil end))
    s:emit(Event("one")); s:emit(Event("many")); s:emit(Event("none"))
    if #s:takeResidue() ~= 3 then fail("nil / Event / list returns do not settle as declared") end
  end)

  local function rej(label, s, entry, want)
    local before = #s:sampleHere().residue
    local r = emitCatch(s, entry)
    if r == nil then fail(label .. ": settled, expected " .. want.code); return end
    add(p, label, M.rejection_problems(r, want))
    if #s:sampleHere().residue ~= before then fail(label .. ": a rejection committed residue") end
  end

  guard("rejections", function()
    local s = Stream()
    s:emit(Event("earlier"))
    s:register(gate(core, "loop", function() return { Event("other"), Event("loop") } end))
    rej("self-loop", s, Event("loop"), { code = "SISO_SELF_LOOP", eventId = 1, eventType = "loop", entry = true })

    local c = Stream()
    c:register(gate(core, "a", function() return Event("b") end))
    c:register(gate(core, "b", function() return Event("a") end))
    rej("cycle", c, Event("a"), { code = "SISO_CYCLE", eventId = 3, causeId = 2, eventType = "a", depth = 3 })

    local t = Stream()
    local thrown = { "the gate's own value" }
    t:register(gate(core, "a", function() return Event("b") end))
    t:register(gate(core, "b", function() error(thrown) end))
    rej("gate threw", t, Event("a"), { code = "SISO_GATE_THREW", eventId = 2, causeId = 1, eventType = "b", cause = thrown })
    -- ids are per propagation: the same entry names the same deliveries again
    rej("gate threw, again", t, Event("a"), { code = "SISO_GATE_THREW", eventId = 2, causeId = 1 })

    local n = Stream()
    rej("entry not an Event", n, { type = "x", data = {} }, { code = "SISO_NOT_EVENT", noDelivery = true })
    rej("entry nil", n, nil, { code = "SISO_NOT_EVENT", noDelivery = true })
    n:register(gate(core, "bad", function() return { Event("ok"), { type = "fake" } } end))
    rej("output not an Event", n, Event("bad"), { code = "SISO_NOT_EVENT", eventId = 1, eventType = "bad" })
    n:register(gate(core, "holes", function() return { Event("ok"), nil, Event("ok") } end))
    rej("output list with a hole", n, Event("holes"), { code = "SISO_NOT_EVENT", eventId = 1 })
    n:register(gate(core, "num", function() return 42 end))
    rej("output a number", n, Event("num"), { code = "SISO_NOT_EVENT" })
    n:register(gate(core, "f", function() return false end))
    rej("output false", n, Event("f"), { code = "SISO_NOT_EVENT" })

    local u = Stream()
    u:register(gate(core, "boom", function() error("x") end))
    emitCatch(u, Event("boom"))
    u:emit(Event("fine"))
    if #u:takeResidue() ~= 1 then fail("the stream is not usable after a rejection") end
  end)

  return p
end

function check.readonly(core)
  local p = {}
  local Event, Stream = core.Event, core.Stream
  local function fail(s) p[#p + 1] = s end
  local function refuses(label, fn)
    if pcall(fn) then fail(label .. " was accepted") end
  end
  local mark = {}
  local sentinel = setmetatable({}, { __readonly = true, __newindex = function() error("immutable", 2) end })
  local ok, err = pcall(function()
    -- read-only from construction (core 13): before any stream sees it
    local orig = { v = 1, list = { 1 } }
    local born = Event("a", orig)
    refuses("writing a field of a new event", function() born.type = "z" end)
    refuses("adding a field to a new event", function() born.extra = 1 end)
    refuses("writing a new event's data", function() born.data.v = 2 end)
    refuses("writing into a new event's nested data", function() born.data.list[1] = 0 end)
    refuses("writing a new event's default data", function() Event("t").data.x = 1 end)
    if keysOf(born) ~= "data,type" then fail("a new event's fields are " .. keysOf(born)) end
    orig.v = 2
    orig.list[1] = 9
    orig.late = true
    if born.data.v ~= 1 or born.data.list[1] ~= 1 or born.data.late ~= nil then
      fail("a write to the table passed to Event reached the event")
    end
    local inner = Event("i")
    local carried = Event("o", { inner = inner, d = born.data, n = sentinel })
    if not rawequal(carried.data.inner, inner) then fail("an event carried in data lost its identity") end
    if not rawequal(carried.data.d, born.data) then fail("read-only data passed on lost its identity") end
    if not rawequal(carried.data.n, sentinel) then fail("a __readonly table in new data lost its identity") end
    if not Event.is(born) then fail("Event.is does not recognise a new event") end

    -- stateless through the event (core 13.0.0)
    if getmetatable(born) ~= "siso.event" then
      fail("getmetatable(event) answers " .. tostring(getmetatable(born)) .. ", not \"siso.event\"")
    end
    if type(getmetatable(born.data)) == "table" or type(getmetatable(born.data.list)) == "table" then
      fail("plain data in an event answers getmetatable with a table")
    end
    local stringSentinel = setmetatable({}, {
      __metatable = "readonly:core-api check",
      __newindex = function() error("immutable", 2) end,
    })
    if not rawequal(Event("s", { s = stringSentinel }).data.s, stringSentinel) then
      fail("a \"readonly:\" sentinel lost its identity")
    end
    local function countRun()
      local cs = Stream()
      cs:register(core.Gate("count", function(e)
        local n = 0
        pcall(function()
          local mt = getmetatable(e)
          mt.n = (mt.n or 0) + 1
          n = mt.n
        end)
        pcall(function()
          local mt = getmetatable(e.data)
          mt.n = (mt.n or 0) + 1
          n = n + mt.n
        end)
        return Event("counted", { n = n })
      end))
      cs:emit(Event("count", {}))
      return cs:takeResidue()[1].data.n
    end
    countRun()
    if countRun() ~= 0 then
      fail("a gate carried a count from one stream to another through what its event's metatables hand out")
    end

    local seen
    local s = Stream()
    s:register(core.Gate("a", function(e)
      seen = e
      refuses("a gate writing its event's data", function() e.data.v = 2 end)
      refuses("a gate writing into nested data", function() e.data.list[1] = 0 end)
      if not rawequal(e.data.list, e.data.list) then fail("two reads of one field give different tables") end
      return Event("b", { list = setmetatable({ 1, 2 }, mark), v = e.data.v, n = sentinel })
    end))
    s:emit(Event("a", { v = 1, list = { 1, 2 } }))
    refuses("writing a field of an event", function() seen.type = "z" end)
    if not Event.is(seen) then fail("Event.is does not recognise a frozen event") end
    local r = s:sampleHere().residue[1]
    if not rawequal(r.data.n, sentinel) then fail("a __readonly table lost its identity") end
    if not Event.is(r) then fail("Event.is does not recognise a sampled event") end
    refuses("writing a sampled event's data", function() r.data.v = 99 end)
    refuses("writing into a sampled event's nested data", function() r.data.list[1] = 9 end)
    if s:sampleHere().residue[1].data.v ~= 1 then fail("a sample's write reached the stream") end
    local l = r.data.list
    if #l ~= 2 or l[2] ~= 2 then fail("# and indexing do not read a frozen list") end
    local n = 0
    for _, v in ipairs(l) do n = n + v end
    if n ~= 3 then fail("ipairs does not read a frozen list") end
    local keys = 0
    for _ in pairs(r.data) do keys = keys + 1 end
    if keys ~= 3 then fail("pairs does not read frozen data") end
    if getmetatable(l) ~= mark then fail("getmetatable on frozen data does not answer the original's metatable") end
    local t = s:takeResidue()[1]
    refuses("writing a taken event's data", function() t.data.v = 5 end)
  end)
  if not ok then fail("raised " .. tostring(err)) end
  return p
end

function check.ownership(core)
  local p = {}
  local Event, Stream = core.Event, core.Stream
  local function fail(s) p[#p + 1] = s end
  local ok, err = pcall(function()
    local s = Stream()
    s:emit(Event("x", { v = 1, n = { 1 } }))
    local a = s:sampleHere().residue[1]
    rawset(a, "type", "z")
    rawset(a.data, "v", 7)
    rawset(a.data.n, 1, 9)
    local b = s:sampleHere().residue[1]
    if b.type ~= "x" then fail("a rawset on a sampled event reached the stream") end
    if b.data.v ~= 1 then fail("a rawset on a sampled event's data reached the stream") end
    if b.data.n[1] ~= 1 then fail("a rawset on a sampled event's nested data reached the stream") end
    if pcall(function() b.data.v = 2 end) then fail("a sampled copy accepts assignment") end
    if not Event.is(b) then fail("Event.is does not recognise a sampled copy") end
    local t = s:takeResidue()[1]
    if t.type ~= "x" or t.data.v ~= 1 or t.data.n[1] ~= 1 then
      fail("a rawset on a sample reached the residue later taken")
    end

    -- an entry too deep to copy is refused as a rejection, never raised
    -- raw (nothing else is raised): every depth Event accepts
    for _, depth in ipairs({ 1000, 30000, 59000, 61000 }) do
      local deep = {}
      local cur = deep
      for _ = 1, depth do cur.x = {}; cur = cur.x end
      local okB, built = pcall(Event, "deep", deep)
      if okB then
        local ds = Stream()
        local okE, raisedE = pcall(ds.emit, ds, built)
        if not okE and not (type(raisedE) == "table" and M.CODES[raisedE.code]) then
          fail("an entry " .. depth .. " deep made emit raise " .. tostring(raisedE):sub(1, 60) .. ", not a rejection")
        end
      end
    end

    -- entry takes the stream's own copy (core 13.0.0)
    local h = Stream()
    local held = Event("x", { v = 1, n = { 1 } })
    h:emit(held)
    rawset(held, "type", "z")
    rawset(held.data, "v", 7)
    rawset(held.data.n, 1, 9)
    -- a take hands over the stream's own objects, so it shows what it held
    local back = h:takeResidue()[1]
    if rawequal(back, held) or back.type ~= "x" or back.data.v ~= 1 or back.data.n[1] ~= 1 then
      fail("a rawset on the entering code's own event reached the residue")
    end
    h:emit(back)
    rawset(back.data, "v", 8)
    local again = h:takeResidue()[1]
    if rawequal(again, back) or again.data.v ~= 1 then
      fail("a rawset on taken residue entered again reached the residue")
    end
  end)
  if not ok then fail("raised " .. tostring(err)) end
  return p
end

return M
