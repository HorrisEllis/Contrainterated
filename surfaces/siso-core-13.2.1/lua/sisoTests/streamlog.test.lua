-- streamlog: observer.streamlog (code/observer/streamlog.lua) on the core 12
-- observer seam. Ports the intent of 11.1's legacy/js/tests/streamlog.test.js
-- (26/26 on node). Expected values for unchanged behaviour come from that
-- oracle; where 12 differs (batches per propagation, per-propagation ids,
-- labels instead of nextStreamId, boolean claimed) a comment says so.
-- Integration cases drive sisoTests/contract-fake-core.lua, not the real core.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local StreamLog = require("observer.streamlog")
local seam = require("contracts.observer_seam")
local fake = require("contract-fake-core")

local core = fake.new()
local Event, Gate, Stream = core.Event, core.Gate, core.Stream

-- A hand-built batch stands in for 11.1's direct log.record(…) calls.
-- 12: the log is fed a whole propagation, never one delivery.
local function rec(id, cause, t, claimed, data)
  return { eventId = id, causeId = cause, eventType = t, claimed = claimed, data = data or {} }
end
local function batch(records, outcome, rejection)
  return { outcome = outcome or "settled", rejection = rejection, records = records }
end
local function one(t, claimed, data) return batch({ rec(1, nil, t, claimed, data) }) end

-- Walk causeId within one propagation of one stream: → "t1,t2,…".
local function thread(entries, label, propagation, fromId)
  local byId = {}
  for _, e in ipairs(entries) do
    if e.propagation == propagation and (label == nil or e.streamLabel == label) then byId[e.eventId] = e end
  end
  local out, cur = {}, byId[fromId]
  while cur do
    table.insert(out, 1, cur.type)
    cur = cur.causeId ~= nil and byId[cur.causeId] or nil
  end
  return table.concat(out, ",")
end

-- ── Levels ─────────────────────────────────────

H.test("StreamLog: OFF records nothing", function()
  local log = StreamLog("OFF")
  log:observer("s")(one("x", true))
  H.assertEqual(log:sample().count, 0)
end)

H.test("StreamLog: default level is EVENTS", function()
  H.assertEqual(StreamLog():level(), "EVENTS")
end)

H.test("StreamLog: EVENTS records type and claimed", function()
  local log = StreamLog("EVENTS")
  log:observer("s")(one("foo", true, { big = true }))
  local e = log:sample().entries
  H.assertEqual(#e, 1)
  H.assertEqual(e[1].type, "foo")
  -- 12: claimed is the seam's boolean (11.1 gave the signature, which is the type)
  H.assertEqual(e[1].claimed, true)
  H.assertEqual(e[1].eventId, 1)
  H.assertEqual(e[1].causeId, nil)
  H.assertEqual(e[1].propagation, 1)
  H.assertEqual(e[1].outcome, "settled")
  H.assert(e[1].streamLabel == nil, "no streamLabel at EVENTS")
  H.assert(e[1].data == nil, "no data at EVENTS")
end)

H.test("StreamLog: EVENTS records claimed false for residue", function()
  -- 11.1: claimed null; 12: false
  local log = StreamLog("EVENTS")
  log:observer("s")(one("orphan", false))
  H.assertEqual(log:sample().entries[1].claimed, false)
end)

H.test("StreamLog: DEEP includes streamLabel and parentLabel", function()
  -- 12: labels chosen by whoever attaches, in place of 11.1's numeric stream ids
  local log = StreamLog("DEEP")
  log:observer("parent")
  log:observer("child", "parent")(one("x", true))
  local e = log:sample().entries[1]
  H.assertEqual(e.streamLabel, "child")
  H.assertEqual(e.parentLabel, "parent")
  H.assert(e.data == nil, "no data at DEEP")
end)

H.test("StreamLog: DEEP omits parentLabel when there is none", function()
  local log = StreamLog("DEEP")
  log:observer("s")(one("x", false))
  local e = log:sample().entries[1]
  H.assertEqual(e.streamLabel, "s")
  H.assertEqual(e.parentLabel, nil)
end)

H.test("StreamLog: DATA includes everything", function()
  local log = StreamLog("DATA")
  log:observer("p")
  log:observer("c", "p")(one("y", true, { val = 42 }))
  local e = log:sample().entries[1]
  H.assertEqual(e.streamLabel, "c")
  H.assertEqual(e.parentLabel, "p")
  H.assertEqual(e.data.val, 42)
end)

-- ── Unknown levels (X2) ────────────────────────

H.test("StreamLog: an unknown level is refused at construction (X2)", function()
  -- 11.1: an unknown level silently acted as OFF
  H.assertThrows(function() StreamLog("VERBOSE") end, "unknown level 'VERBOSE'")
  H.assertThrows(function() StreamLog("events") end, "unknown level")
  H.assertThrows(function() StreamLog(3) end, "unknown level")
end)

H.test("StreamLog: an unknown level is refused when set, and the level is kept (X2)", function()
  local log = StreamLog("DEEP")
  H.assertThrows(function() log:setLevel("LOUD") end, "unknown level 'LOUD'")
  H.assertThrows(function() log:setLevel(nil) end, "unknown level")
  H.assertEqual(log:level(), "DEEP")
  log:observer("s")(one("x", true))
  H.assertEqual(log:sample().entries[1].streamLabel, "s", "still recording at DEEP")
end)

-- ── Sequencing ─────────────────────────────────

H.test("StreamLog: seq increments", function()
  local log = StreamLog("EVENTS")
  local obs = log:observer("s")
  obs(batch({ rec(1, nil, "a", true), rec(2, 1, "b", false) }))
  obs(one("c", false))
  local e = log:sample().entries
  H.assertEqual(e[1].seq, 0)
  H.assertEqual(e[2].seq, 1)
  H.assertEqual(e[3].seq, 2)
end)

H.test("StreamLog: entries have timestamps", function()
  -- 12: os.time() seconds (11.1: Date.now() milliseconds)
  local log = StreamLog("EVENTS")
  local before = os.time()
  log:observer("s")(one("x", false))
  local after = os.time()
  local t = log:sample().entries[1].time
  H.assert(math.type(t) == "integer" and t >= before and t <= after, "time within the call")
end)

-- ── Labels (12, in place of nextStreamId) ──────

H.test("StreamLog: labels are per log and unique within it", function()
  local a, b = StreamLog(), StreamLog()
  a:observer("s")
  b:observer("s") -- no module-global counter or registry: another log is unaffected
  H.assertThrows(function() a:observer("s") end, "already used")
  H.assertThrows(function() a:observer("") end, "non-empty string")
  H.assertThrows(function() a:observer(7) end, "non-empty string")
  H.assertThrows(function() a:observer("t", "nope") end, "not a label of this log")
  H.assertThrows(function() b:observer("t", "x") end, "not a label of this log")
end)

H.test("StreamLog: propagations are numbered per observed stream", function()
  local log = StreamLog("DEEP")
  local p, c = log:observer("p"), log:observer("c", "p")
  p(one("a", false)); c(one("b", false)); p(one("d", false))
  local e = log:sample().entries
  H.assertEqual(e[1].propagation, 1)
  H.assertEqual(e[2].propagation, 1)
  H.assertEqual(e[3].propagation, 2)
end)

H.test("StreamLog: propagations at OFF are still counted", function()
  local log = StreamLog("OFF")
  local obs = log:observer("s")
  obs(one("a", false))
  log:setLevel("EVENTS")
  obs(one("b", false))
  local e = log:sample().entries
  H.assertEqual(#e, 1)
  H.assertEqual(e[1].propagation, 2)
end)

-- ── sample and clear ───────────────────────────

H.test("StreamLog: sample returns copy", function()
  local log = StreamLog("EVENTS")
  log:observer("s")(one("x", false))
  local s = log:sample()
  table.remove(s.entries)
  H.assertEqual(log:sample().count, 1)
  local s2 = log:sample()
  s2.entries[1].type = "changed"
  H.assertEqual(log:sample().entries[1].type, "x", "an entry write does not reach the log")
  H.assertEqual(s2.level, "EVENTS")
end)

H.test("StreamLog: clear resets entries and seq", function()
  local log = StreamLog("EVENTS")
  local obs = log:observer("s")
  obs(batch({ rec(1, nil, "x", true), rec(2, 1, "y", false) }))
  log:clear()
  H.assertEqual(log:sample().count, 0)
  obs(one("z", false))
  local e = log:sample().entries[1]
  H.assertEqual(e.seq, 0, "seq resets")
  H.assertEqual(e.propagation, 2, "propagation numbers are kept")
end)

-- ── Runtime level change ───────────────────────

H.test("StreamLog: level change takes effect immediately", function()
  local log = StreamLog("OFF")
  local obs = log:observer("s")
  obs(one("a", false))
  H.assertEqual(log:sample().count, 0)
  log:setLevel("EVENTS")
  obs(one("b", false))
  H.assertEqual(log:sample().count, 1)
  log:setLevel("OFF")
  obs(one("c", false))
  H.assertEqual(log:sample().count, 1)
end)

H.test("StreamLog: upgrade level mid-run adds detail", function()
  local log = StreamLog("EVENTS")
  local obs = log:observer("s")
  obs(one("a", false, { x = 1 }))
  log:setLevel("DATA")
  obs(one("b", false, { x = 2 }))
  local e = log:sample().entries
  H.assert(e[1].data == nil, "first entry has no data")
  H.assertEqual(e[2].data.x, 2, "second entry has data")
end)

-- ── Integration with a stream (contract fake core) ───

H.test("StreamLog: what it is shown passes the seam's batch check", function()
  local s = Stream()
  local log = StreamLog("DATA")
  local obs = log:observer("s")
  local shown
  s:attach(function(b) shown = b end)
  s:attach(obs)
  s:register(Gate("root", function() return { Event("a"), Event("b") } end))
  s:emit(Event("root", { k = 1 }))
  H.assertEqual(#seam.batch_problems(shown), 0)
  local e = log:sample().entries
  H.assertEqual(#e, 3)
  H.assertEqual(e[1].data.k, 1)
end)

H.test("StreamLog: Stream.emit records to log", function()
  local log = StreamLog("EVENTS")
  local s = Stream()
  s:attach(log:observer("s"))
  s:register(Gate("sink", function() return nil end))
  s:emit(Event("sink", {}))
  s:emit(Event("orphan", {}))
  local e = log:sample().entries
  H.assertEqual(#e, 2)
  H.assertEqual(e[1].claimed, true)
  H.assertEqual(e[2].claimed, false)
end)

H.test("StreamLog: shared log across parent and sub-stream", function()
  local log = StreamLog("DEEP")
  local parent, child = Stream(), Stream()
  parent:attach(log:observer("parent"))
  child:attach(log:observer("child", "parent"))
  parent:emit(Event("parent_event", {}))
  child:emit(Event("child_event", {}))
  local e = log:sample().entries
  H.assertEqual(#e, 2)
  H.assertEqual(e[1].streamLabel, "parent")
  H.assertEqual(e[1].parentLabel, nil)
  H.assertEqual(e[2].streamLabel, "child")
  H.assertEqual(e[2].parentLabel, "parent")
  -- 12: both are delivery 1 of propagation 1 of their own stream; the label disambiguates
  H.assertEqual(e[1].eventId, e[2].eventId)
end)

H.test("StreamLog: returned events are recorded via log", function()
  local log = StreamLog("EVENTS")
  local s = Stream()
  s:attach(log:observer("s"))
  s:register(Gate("forward", function(ev) return Event("forwarded", { from = ev.data.origin }) end))
  s:emit(Event("forward", { origin = "test" }))
  local e = log:sample().entries
  H.assertEqual(#e, 2)
  H.assertEqual(e[1].type, "forward")
  H.assertEqual(e[1].claimed, true)
  H.assertEqual(e[2].type, "forwarded")
  H.assertEqual(e[2].claimed, false)
end)

-- ── Causal threads ─────────────────────────────

H.test("StreamLog: chain entries carry eventId and causeId", function()
  local log = StreamLog("EVENTS")
  local s = Stream()
  s:attach(log:observer("s"))
  s:register(Gate("x", function() return Event("y") end))
  s:register(Gate("y", function() return Event("z") end))
  s:emit(Event("x", {}))
  local e = log:sample().entries
  H.assertEqual(#e, 3)
  H.assertEqual(e[1].eventId, 1)
  H.assertEqual(e[1].causeId, nil, "external emit has no cause")
  H.assertEqual(e[2].eventId, 2)
  H.assertEqual(e[2].causeId, 1, "y caused by x")
  H.assertEqual(e[3].eventId, 3)
  H.assertEqual(e[3].causeId, 2, "z caused by y")
end)

H.test("StreamLog: fan-out siblings share a cause", function()
  local log = StreamLog("EVENTS")
  local s = Stream()
  s:attach(log:observer("s"))
  s:register(Gate("root", function() return { Event("left"), Event("right") } end))
  s:emit(Event("root", {}))
  local e = log:sample().entries
  H.assertEqual(#e, 3)
  H.assertEqual(e[2].causeId, e[1].eventId, "left caused by root")
  H.assertEqual(e[3].causeId, e[1].eventId, "right caused by root")
end)

H.test("StreamLog: separate external emits are separate roots", function()
  local log = StreamLog("EVENTS")
  local s = Stream()
  s:attach(log:observer("s"))
  s:emit(Event("a", {}))
  s:emit(Event("b", {}))
  local e = log:sample().entries
  H.assertEqual(e[1].causeId, nil)
  H.assertEqual(e[2].causeId, nil)
  -- 12: ids restart in every propagation (11.1: "ids continue across emits");
  -- the log's propagation number tells the two apart
  H.assertEqual(e[1].eventId, 1)
  H.assertEqual(e[2].eventId, 1)
  H.assertEqual(e[1].propagation, 1)
  H.assertEqual(e[2].propagation, 2)
end)

H.test("StreamLog: causal chain reconstructible from a mixed tree", function()
  -- root fans out to two chains; entries interleave by generation, but
  -- walking causeId untangles each thread: order means time, causeId cause.
  local log = StreamLog("DEEP")
  local s = Stream()
  s:attach(log:observer("s"))
  local function mk(sig, outs)
    return Gate(sig, function()
      local r = {}
      for i, t in ipairs(outs) do r[i] = Event(t) end
      return r
    end)
  end
  s:register(mk("root", { "a", "b" }))
  s:register(mk("a", { "a1" }))
  s:register(mk("b", { "b1" }))
  s:register(mk("a1", { "a2" }))
  s:emit(Event("x")) -- 12: an earlier propagation reuses the same ids; the walk must not cross it
  s:emit(Event("root", {}))
  local entries = log:sample().entries
  local a2
  for _, e in ipairs(entries) do if e.type == "a2" then a2 = e end end
  H.assertEqual(thread(entries, "s", a2.propagation, a2.eventId), "root,a,a1,a2")
end)

-- ── Acyclicity diagnostics ─────────────────────

H.test("StreamLog: entries carry no retries field", function()
  local log = StreamLog("DATA")
  local s = Stream()
  s:attach(log:observer("s"))
  s:emit(Event("plain", {}))
  local e = log:sample().entries
  H.assertEqual(#e, 1)
  H.assert(e[1].retries == nil, "no retries in core 12")
end)

H.test("StreamLog: a cycle is readable from the causal chain", function()
  -- The rejection cannot name the loop by itself (K5); an attached log can,
  -- by walking causeId from the delivery the rejection names.
  local log = StreamLog("EVENTS")
  local s = Stream()
  s:attach(log:observer("s"))
  s:register(Gate("a", function() return Event("b") end))
  s:register(Gate("b", function() return Event("a") end))
  local ok, err = pcall(s.emit, s, Event("a", {}))
  H.assert(not ok, "the cycle rejects")
  H.assertEqual(err.code, "SISO_CYCLE")
  local entries = log:sample().entries
  local last = entries[#entries]
  H.assertEqual(last.eventId, err.eventId, "the records end with the delivery the rejection names")
  H.assertEqual(last.outcome, "rejected")
  H.assertEqual(thread(entries, nil, last.propagation, err.eventId), "a,b,a")
end)

H.test("StreamLog: self-loop rejection points at the producing entry", function()
  local log = StreamLog("EVENTS")
  local s = Stream()
  s:attach(log:observer("s"))
  s:register(Gate("loop", function() return Event("loop") end))
  local ok, err = pcall(s.emit, s, Event("loop", {}))
  H.assert(not ok, "the self-loop rejects")
  H.assertEqual(err.code, "SISO_SELF_LOOP")
  local e = log:sample().entries
  H.assertEqual(#e, 1, "the looping return is never delivered")
  H.assertEqual(e[1].eventId, err.eventId)
  H.assertEqual(e[1].claimed, true)
  H.assertEqual(e[1].outcome, "rejected")
end)

H.test("StreamLog: gate-threw rejection points at the claiming entry", function()
  local log = StreamLog("EVENTS")
  local s = Stream()
  s:attach(log:observer("s"))
  s:register(Gate("a", function() return Event("b") end))
  s:register(Gate("b", function() error("boom") end))
  local ok, err = pcall(s.emit, s, Event("a", {}))
  H.assert(not ok, "the throw rejects")
  H.assertEqual(err.code, "SISO_GATE_THREW")
  local found
  for _, x in ipairs(log:sample().entries) do if x.eventId == err.eventId then found = x end end
  H.assert(found, "the rejection names a logged delivery")
  H.assertEqual(found.type, "b")
  H.assertEqual(found.claimed, true)
  H.assertEqual(found.causeId, err.causeId)
end)

H.test("StreamLog: a refused entry is a propagation with no entries", function()
  local log = StreamLog("EVENTS")
  local s = Stream()
  local obs = log:observer("s")
  s:attach(obs)
  pcall(s.emit, s, "not an event")
  H.assertEqual(log:sample().count, 0)
  s:emit(Event("ok"))
  H.assertEqual(log:sample().entries[1].propagation, 2)
end)

H.test("StreamLog: log presence does not change acyclicity outcome", function()
  local function run(withLog)
    local s = Stream()
    if withLog then s:attach(StreamLog("DATA"):observer("s")) end
    s:register(Gate("a", function() return Event("b") end))
    s:register(Gate("b", function() return Event("a") end))
    local ok, e = pcall(s.emit, s, Event("a", {}))
    if ok then return "none" end
    return e.code .. "@" .. tostring(e.eventId) .. "/" .. tostring(e.depth)
  end
  H.assertEqual(run(true), run(false))
end)

H.test("StreamLog: log absence changes nothing", function()
  -- 12: sampleHere carries no eventCount (core-api), so compare residue only
  local function run(withLog)
    local s = Stream()
    if withLog then s:attach(StreamLog("EVENTS"):observer("s")) end
    s:register(Gate("x", function() return Event("y") end))
    s:emit(Event("x", {}))
    local r = s:sampleHere().residue
    return #r .. ":" .. r[1].type
  end
  H.assertEqual(run(true), run(false))
end)

H.test("StreamLog: detaching stops recording; re-attaching continues numbering", function()
  local log = StreamLog("EVENTS")
  local s = Stream()
  local obs = log:observer("s")
  s:attach(obs)
  s:emit(Event("a"))
  s:detach(obs)
  s:emit(Event("b"))
  H.assertEqual(log:sample().count, 1)
  s:attach(obs)
  s:emit(Event("c"))
  local e = log:sample().entries
  H.assertEqual(#e, 2)
  H.assertEqual(e[2].propagation, 2)
end)

H.finish("streamlog")
