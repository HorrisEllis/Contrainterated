-- core.test.lua — port of core 11.1 legacy/js/tests/core.test.js.
-- One Lua test per JS test, same names, same expectations (taken from
-- the JS oracle, never from running this code). Only JS idioms are
-- adapted: arguments.length → select('#', ...), Object.keys → the
-- table's keys, instanceof Event → Event.is, thrown Error objects → a
-- raised table carrying the same fields plus message, with __tostring
-- returning the message, Gate subclasses → Gate(signature, transform).
-- The gates below are fixtures for the stream, not project gates.
-- 12.0 (stream-doors): the tests that pinned eventCount and the log
-- option are rewritten to the core-api / observer-seam contracts; each
-- change is named in a "12.0:" comment. Every other test is the oracle's.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local Event = require("core.event")
local Gate = require("core.gate")
local Stream = require("core.stream")

local test, assert, assertEqual, assertThrows = H.test, H.assert, H.assertEqual, H.assertThrows

-- JS `new Error(message)`: a table carrying message, shown as its message.
local ErrorMeta = { __tostring = function(e) return e.message end }
local function Error(message, name)
  return setmetatable({ name = name or "Error", message = message }, ErrorMeta)
end
local function isError(v)
  return type(v) == "table" and type(v.message) == "string" and tostring(v) == v.message
end

-- Object.keys(t).sort().join(',')
local function keysOf(t)
  local ks = {}
  for k in pairs(t) do ks[#ks + 1] = tostring(k) end
  table.sort(ks)
  return table.concat(ks, ",")
end
local function keyCount(t)
  local n = 0
  for _ in pairs(t) do n = n + 1 end
  return n
end
local function includes(list, v)
  for _, x in ipairs(list) do if x == v then return true end end
  return false
end
-- try { s.emit(e) } catch (e) { err = e }
local function emitCatch(s, e)
  local ok, err = pcall(s.emit, s, e)
  if ok then return nil end
  return err
end
-- out.map(t => new Event(t, {}))
local function events(types)
  local list = {}
  for i, t in ipairs(types) do list[i] = Event(t, {}) end
  return list
end

-- ── Event ──────────────────────────────────────

test("Event: type and data", function()
  local e = Event("foo", { x = 1 })
  assertEqual(e.type, "foo")
  assertEqual(e.data.x, 1)
end)

test("Event: data defaults to empty object", function()
  local e = Event("bar")
  assertEqual(e.type, "bar")
  assert(type(e.data) == "table")
  assertEqual(keyCount(e.data), 0)
end)

-- ── Gate ───────────────────────────────────────

test("Gate: signature stored", function()
  local g = Gate("test_sig")
  assertEqual(g.signature, "test_sig")
end)

test("Gate: transform returns undefined by default", function()
  local g = Gate("noop")
  local result = g.transform(Event("noop"))
  assertEqual(result, nil)
end)

-- ── Stream: registration ───────────────────────

test("Stream: register and lookup", function()
  local s = Stream()
  local g = Gate("x")
  s:register(g)
  assert(s.gates["x"] ~= nil)
end)

test("Stream: signature collision throws", function()
  local s = Stream()
  s:register(Gate("x"))
  assertThrows(function() s:register(Gate("x")) end, "Signature collision")
end)

test("Stream: different signatures coexist", function()
  local s = Stream()
  s:register(Gate("a"))
  s:register(Gate("b"))
  assertEqual(keyCount(s.gates), 2)
end)

-- ── Stream: emit and residue ───────────────────

test("Stream: unclaimed event goes to residue", function()
  local s = Stream()
  s:emit(Event("unknown", { v = 42 }))
  local sample = s:sampleHere()
  assertEqual(#sample.residue, 1)
  assertEqual(sample.residue[1].type, "unknown")
  assertEqual(sample.residue[1].data.v, 42)
end)

test("Stream: claimed event does not go to residue", function()
  local s = Stream()
  s:register(Gate("sink", function() return nil end))
  s:emit(Event("sink", {}))
  assertEqual(#s:sampleHere().residue, 0)
end)

test("Stream: gate receives event only", function()
  local transformArgs = nil
  local s = Stream()
  s:register(Gate("spy", function(...)
    local event = ...
    transformArgs = {
      argCount = select("#", ...),
      type = event.type,
      data = event.data,
    }
    return nil
  end))
  s:emit(Event("spy", { val = 99 }))
  assertEqual(transformArgs.argCount, 1)
  assertEqual(transformArgs.type, "spy")
  assertEqual(transformArgs.data.val, 99)
end)

test("Stream: gate returns single event → re-emitted", function()
  local s = Stream()
  s:register(Gate("double", function(event)
    return Event("result", { value = event.data.x * 2 })
  end))
  s:emit(Event("double", { x = 5 }))
  local sample = s:sampleHere()
  assertEqual(#sample.residue, 1)
  assertEqual(sample.residue[1].data.value, 10)
end)

test("Stream: gate returns array of events → all re-emitted", function()
  local s = Stream()
  s:register(Gate("split", function(event)
    return {
      Event("part_a", { half = event.data.x // 2 }),
      Event("part_b", { doubled = event.data.x * 2 }),
    }
  end))
  s:emit(Event("split", { x = 10 }))
  local sample = s:sampleHere()
  assertEqual(#sample.residue, 2)
  assertEqual(sample.residue[1].data.half, 5)
  assertEqual(sample.residue[2].data.doubled, 20)
end)

test("Stream: gate returns null → consumed, no follow-up", function()
  local s = Stream()
  s:register(Gate("consume", function() return nil end))
  -- 12.0: no eventCount; the one delivery is seen in an observer's batch
  local batches = {}
  s:attach(function(b) batches[#batches + 1] = b end)
  s:emit(Event("consume", {}))
  assertEqual(#s:sampleHere().residue, 0)
  assertEqual(#batches, 1, "one batch")
  assertEqual(#batches[1].records, 1, "one delivery")
  assertEqual(batches[1].records[1].claimed, true, "claimed by the gate")
end)

test("Stream: gate returns undefined → same as null", function()
  local s = Stream()
  s:register(Gate("silent", function() --[[ no return ]] end))
  s:emit(Event("silent", {}))
  assertEqual(#s:sampleHere().residue, 0)
end)

test("Stream: chain processes in causal order", function()
  local order = {}
  local s = Stream()
  s:register(Gate("a", function()
    order[#order + 1] = "a"
    return Event("b", {})
  end))
  s:register(Gate("b", function()
    order[#order + 1] = "b"
    return Event("c", {})
  end))
  s:register(Gate("c", function()
    order[#order + 1] = "c"
    return nil
  end))
  s:emit(Event("a", {}))
  assertEqual(table.concat(order, ","), "a,b,c")
end)

test("Stream: fan-out delivers all branches", function()
  local order = {}
  local s = Stream()
  s:register(Gate("root", function()
    return {
      Event("left", {}),
      Event("right", {}),
    }
  end))
  s:register(Gate("left", function() order[#order + 1] = "left"; return nil end))
  s:register(Gate("right", function() order[#order + 1] = "right"; return nil end))
  s:emit(Event("root", {}))
  assertEqual(#order, 2)
  assert(includes(order, "left"), "left branch fired")
  assert(includes(order, "right"), "right branch fired")
end)

-- ── Stream: delivery discipline ────────────────

test("Stream: delivery is breadth-first — causal generations", function()
  local order = {}
  local function mk(sig, out)
    return Gate(sig, function()
      order[#order + 1] = sig
      return out and events(out) or nil
    end)
  end
  local s = Stream()
  s:register(mk("root", { "a", "b" }))
  s:register(mk("a", { "a1" }))
  s:register(mk("b", { "b1" }))
  s:register(mk("a1", nil))
  s:register(mk("b1", nil))
  s:emit(Event("root", {}))
  -- Generation by generation: root, then both children,
  -- then both grandchildren. Depth-first would give
  -- root,a,a1,b,b1. Order is not semantic (Rule 3) —
  -- this pins the implementation choice, not a contract.
  assertEqual(table.concat(order, ","), "root,a,b,a1,b1")
end)

test("Stream: chain length unbounded by call stack", function()
  -- A legal chain of N distinct gates: step_0 → step_1 → …
  -- No type repeats, so acyclicity holds — chain length is
  -- bounded by gate count, exactly as Rule 3 permits. The
  -- recursive implementation overflowed the engine stack on
  -- chains this long; queue delivery must not.
  local N = 20000
  local function Step(i)
    return Gate("step_" .. i, function()
      if i < N - 1 then return Event("step_" .. (i + 1), {}) end
      return nil
    end)
  end
  local s = Stream()
  for i = 0, N - 1 do s:register(Step(i)) end
  -- 12.0: no eventCount; an observer's batch shows the N deliveries
  local deliveries = nil
  s:attach(function(b) deliveries = #b.records end)
  s:emit(Event("step_0", {}))
  assertEqual(deliveries, N)
  assertEqual(#s:sampleHere().residue, 0)
end)

test("Stream: events are never annotated by delivery", function()
  local s = Stream()
  s:register(Gate("pass", function() return Event("out", { v = 1 }) end))
  local rootEvent = Event("pass", {})
  s:emit(rootEvent)
  local out = s:sampleHere().residue[1]
  assertEqual(keysOf(rootEvent), "data,type", "emitted event untouched")
  assertEqual(keysOf(out), "data,type", "delivered event untouched")
end)

-- ── Event: shape ───────────────────────────────

test("Event: carries only type and data", function()
  -- Core 11 removed the retries field. A stray third argument
  -- must not attach anything to the event.
  local e = Event("foo", { x = 1 }, { attempt = 1, max = 3 })
  assertEqual(keysOf(e), "data,type")
end)

-- ── Stream: acyclicity (enforced) ──────────────

test("Stream: self-loop throws SISO_SELF_LOOP", function()
  local s = Stream()
  s:register(Gate("echo", function(event) return Event("echo", { v = event.data.v + 1 }) end))
  local err = emitCatch(s, Event("echo", { v = 0 }))
  assert(err, "expected a throw")
  assertEqual(err.code, "SISO_SELF_LOOP")
  assertEqual(err.eventType, "echo")
  assertEqual(#s:sampleHere().residue, 0, "nothing committed")
end)

test("Stream: self-loop inside a fan-out array throws", function()
  local s = Stream()
  s:register(Gate("mixed", function() return { Event("other", {}), Event("mixed", {}) } end))
  assertThrows(function() s:emit(Event("mixed", {})) end, "Self-loop")
end)

test("Stream: indirect cycle a → b → a throws SISO_CYCLE", function()
  local s = Stream()
  s:register(Gate("a", function() return Event("b", {}) end))
  s:register(Gate("b", function() return Event("a", {}) end))
  local err = emitCatch(s, Event("a", {}))
  assert(err, "expected a throw — core 10 looped forever here")
  assertEqual(err.code, "SISO_CYCLE")
  -- Caught at the first claimed event deeper than the gate count.
  assertEqual(err.depth, keyCount(s.gates) + 1)
end)

test("Stream: longer indirect cycle a → b → c → a throws", function()
  local function mk(sig, out)
    return Gate(sig, function() return Event(out, {}) end)
  end
  local s = Stream()
  s:register(mk("a", "b"))
  s:register(mk("b", "c"))
  s:register(mk("c", "a"))
  assertThrows(function() s:emit(Event("a", {})) end, "Cycle")
end)

test("Stream: same type in sibling branches is legal", function()
  -- Acyclicity is per causal path, not across the tree.
  local function mk(sig, out)
    return Gate(sig, function() return out and events(out) or nil end)
  end
  local s = Stream()
  s:register(mk("root", { "x", "y" }))
  s:register(mk("x", { "z" }))
  s:register(mk("y", { "z" }))
  s:register(mk("z", { "done" }))
  s:emit(Event("root", {}))
  local residue = s:sampleHere().residue
  assertEqual(#residue, 2, "z ran on both branches")
  local every = true
  for _, e in ipairs(residue) do if e.type ~= "done" then every = false end end
  assert(every)
end)

test("Stream: longest legal chain ends unclaimed at depth gates + 1", function()
  -- Every gate claimed once, then a final unclaimed event.
  -- That is the deepest a legal chain can go — no throw.
  local function mk(sig, out)
    return Gate(sig, function() return Event(out, {}) end)
  end
  local s = Stream()
  s:register(mk("a", "b"))
  s:register(mk("b", "c"))
  s:register(mk("c", "answer"))
  s:emit(Event("a", {}))
  assertEqual(s:sampleHere().residue[1].type, "answer")
end)

test("Stream: a propagation that throws commits no residue", function()
  local function mk(sig, out)
    return Gate(sig, function() return events(out) end)
  end
  local s = Stream()
  s:register(mk("root", { "leftover", "a" }))
  s:register(mk("a", { "b" }))
  s:register(mk("b", { "a" }))
  s:emit(Event("earlier", {}))            -- settles normally
  assertThrows(function() s:emit(Event("root", {})) end, "Cycle")
  local residue = s:sampleHere().residue
  assertEqual(#residue, 1, "only the earlier residue remains")
  assertEqual(residue[1].type, "earlier")
end)

test("Stream: stream remains usable after a throw", function()
  local s = Stream()
  s:register(Gate("echo", function() return Event("echo", {}) end))
  assertThrows(function() s:emit(Event("echo", {})) end, "Self-loop")
  s:emit(Event("fine", {}))
  assertEqual(#s:sampleHere().residue, 1)
end)

test("Stream: a gate that throws rejects with SISO_GATE_THREW, ids and cause", function()
  local original = Error("bad input", "TypeError")
  local s = Stream()
  s:register(Gate("a", function() return Event("b", {}) end))
  s:register(Gate("b", function() error(original) end))
  local err = emitCatch(s, Event("a", {}))
  assert(err ~= nil, "emit threw")
  assertEqual(err.code, "SISO_GATE_THREW")
  assertEqual(err.eventType, "b", "the event the gate was given")
  assertEqual(err.eventId, 2, "the delivery that threw")
  assertEqual(err.causeId, 1, "the delivery that caused it")
  assert(rawequal(err.cause, original), "the gate's own error is kept as cause")
  assert(err.message:find("bad input", 1, true), "message carries the original")
end)

test("Stream: a gate that throws commits no residue; stream stays usable", function()
  local s = Stream()
  s:register(Gate("root", function() return { Event("leftover", {}), Event("b", {}) } end))
  s:register(Gate("b", function() error(Error("boom")) end))
  assertThrows(function() s:emit(Event("root", {})) end, "boom")
  assertEqual(#s:sampleHere().residue, 0, "the queued sibling is not committed")
  s:emit(Event("fine", {}))
  assertEqual(#s:sampleHere().residue, 1)
end)

test("Stream: a gate that throws a non-Error is wrapped the same way", function()
  local s = Stream()
  s:register(Gate("x", function() error("plain string", 0) end))
  local err = emitCatch(s, Event("x", {}))
  assert(isError(err), "a real Error leaves emit")
  assertEqual(err.code, "SISO_GATE_THREW")
  assertEqual(err.cause, "plain string")
  assertEqual(err.causeId, nil, "an external entry has no cause")
end)

-- ── Stream: take residue ───────────────────────

test("Stream: takeResidue returns the residue and removes it", function()
  local s = Stream()
  s:emit(Event("x", { v = 1 }))
  s:emit(Event("y", { v = 2 }))
  local taken = s:takeResidue()
  assertEqual(#taken, 2)
  assertEqual(taken[1].type, "x")
  assertEqual(taken[2].data.v, 2)
  assertEqual(#s:sampleHere().residue, 0, "stream emptied")
end)

test("Stream: residue after a take holds only new arrivals", function()
  local s = Stream()
  s:emit(Event("old", {}))
  s:takeResidue()
  s:emit(Event("new", {}))
  local residue = s:sampleHere().residue
  assertEqual(#residue, 1)
  assertEqual(residue[1].type, "new")
end)

test("Stream: takeResidue on an empty stream returns []", function()
  local s = Stream()
  local taken = s:takeResidue()
  assert(type(taken) == "table" and #taken == 0 and next(taken) == nil)
end)

test("Stream: sampling does not take", function()
  local s = Stream()
  s:emit(Event("x", {}))
  s:sampleHere()
  s:sampleHere()
  assertEqual(#s:takeResidue(), 1)
end)

-- ── Stream: sampleHere ─────────────────────────

test("Stream: sampleHere returns copy of residue", function()
  local s = Stream()
  s:emit(Event("x", {}))
  local sample = s:sampleHere()
  table.remove(sample.residue)
  assertEqual(#s:sampleHere().residue, 1, "original not affected")
end)

test("Stream: eventCount tracks all emits including re-emits", function()
  -- 12.0: eventCount is gone (ledger R1 retired). Each propagation numbers
  -- its own deliveries 1, 2, … including re-emits, shown to an observer;
  -- nothing is counted across propagations and the sample has no counter.
  local s = Stream()
  s:register(Gate("echo", function() return Event("out", {}) end))
  local ids = {}
  s:attach(function(b)
    local t = {}
    for i, r in ipairs(b.records) do t[i] = tostring(r.eventId) end
    ids[#ids + 1] = table.concat(t, ",")
  end)
  s:emit(Event("echo", {}))
  s:emit(Event("echo", {}))
  assertEqual(ids[1], "1,2", "the entry and its re-emit")
  assertEqual(ids[2], "1,2", "the next propagation numbers its own")
  assertEqual(keysOf(s:sampleHere()), "gateCount,residue", "no counter in the sample")
end)

test("Stream: gateCount in sampleHere", function()
  local s = Stream()
  s:register(Gate("a"))
  s:register(Gate("b"))
  s:register(Gate("c"))
  assertEqual(s:sampleHere().gateCount, 3)
end)

test("Stream: residue accumulates across emits", function()
  local s = Stream()
  s:emit(Event("x", {}))
  s:emit(Event("y", {}))
  assertEqual(#s:sampleHere().residue, 2)
end)

-- ── Stream: constructor options ────────────────

test("Stream: no-arg constructor still works", function()
  -- 12.0: Stream() is the only constructor: no gates, no residue, no
  -- observers, and no log, stream id or lineage on the stream.
  local s = Stream()
  assertEqual(s.log, nil)
  assertEqual(s.streamId, nil)
  assertEqual(s.parentStreamId, nil)
  local sample = s:sampleHere()
  assertEqual(#sample.residue, 0)
  assertEqual(sample.gateCount, 0)
  local seen = 0
  s:attach(function() seen = seen + 1 end)
  s:emit(Event("x", {}))
  assertEqual(seen, 1, "the only observer is the one attached")
end)

test("Stream: constructor accepts log", function()
  -- 12.0: Stream takes no options. A log is an observer, attached
  -- through the observe door; a `{ log = … }` argument is not read, so
  -- nothing is called on it and the stream holds no log or stream id.
  local calls = 0
  local fakeLog = { nextStreamId = function() calls = calls + 1; return 42 end,
                    record = function() calls = calls + 1 end }
  local s = Stream({ log = fakeLog, parentStreamId = 7 })
  s:emit(Event("x", {}))
  assertEqual(calls, 0, "the log option is not read")
  assertEqual(s.log, nil)
  assertEqual(s.streamId, nil)
  assertEqual(s.parentStreamId, nil)
  local batches = 0
  s:attach(function() batches = batches + 1 end)
  s:emit(Event("y", {}))
  assertEqual(batches, 1, "an observer attaches through the door")
end)

H.finish("core")
