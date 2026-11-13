-- stream-doors.test.lua — the core's doors against core-api and
-- observer-seam (core 12, phase stream-doors).
-- Runs both contracts' checks on the real core, then pins what the checks
-- leave open. The gates below are fixtures for the stream, not project
-- gates.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local API = require("contracts.core_api")
local SEAM = require("contracts.observer_seam")
local Event = require("core.event")
local Gate = require("core.gate")
local Stream = require("core.stream")
local Rejection = require("core.rejection")

local core = { Event = Event, Gate = Gate, Stream = Stream }
local test, assert, assertEqual = H.test, H.assert, H.assertEqual

local function joined(list) return table.concat(list, "; ") end
local function keysOf(t)
  local ks = {}
  for k in pairs(t) do ks[#ks + 1] = tostring(k) end
  table.sort(ks)
  return table.concat(ks, ",")
end
local function emitCatch(s, e)
  local ok, err = pcall(s.emit, s, e)
  if ok then return nil end
  return err
end
local function noProblems(list, label)
  assert(#list == 0, (label and label .. ": " or "") .. joined(list))
end
-- a stream with one fixture gate `sig` returning `ret`
local function oneGate(sig, ret)
  local s = Stream()
  s:register(Gate(sig, function() return ret end))
  return s
end

-- ── the contracts' own checks ──────────────────

test("core-api: check.doors holds on the real core", function()
  noProblems(API.check.doors(core))
end)

test("observer-seam: check.seam holds on the real core", function()
  noProblems(SEAM.check.seam(core))
end)

-- ── rejection tables (core.rejection) ──────────

test("rejection: each builder carries exactly its code's fields", function()
  local d = { id = 3, causeId = 2, type = "t", depth = 3 }
  local cases = {
    { Rejection.selfLoop(d, "t"), "causeId,code,eventId,eventType,message" },
    { Rejection.cycle(d, 2), "causeId,code,depth,eventId,eventType,message" },
    { Rejection.gateThrew(d, "t", "x"), "cause,causeId,code,eventId,eventType,message" },
    { Rejection.notEvent(d, "t", "a string"), "causeId,code,eventId,eventType,message" },
    { Rejection.notEvent(nil, nil, "nil"), "code,message" },
  }
  for i, c in ipairs(cases) do
    local r = c[1]
    assertEqual(keysOf(r), c[2], "case " .. i)
    noProblems(API.rejection_problems(r), "case " .. i)
    assertEqual(tostring(r), r.message, "case " .. i .. " tostring")
    assert(Rejection.is(r), "case " .. i .. " Rejection.is")
  end
  assert(not Rejection.is({ code = "SISO_CYCLE", message = "m" }), "a plain table is not a rejection")
end)

test("rejection: a gate's raised value is kept exactly, even nil", function()
  local s = Stream()
  s:register(Gate("raises_nil", function() error(nil) end))
  local r = emitCatch(s, Event("raises_nil"))
  assertEqual(r.code, "SISO_GATE_THREW")
  assertEqual(r.cause, nil)
  noProblems(API.rejection_problems(r))
end)

-- ── SISO_SELF_LOOP carries causeId ─────────────

test("SISO_SELF_LOOP names the returning delivery and its cause", function()
  local s = Stream()
  s:register(Gate("fx_head", function() return { Event("fx_side"), Event("fx_loop") } end))
  s:register(Gate("fx_loop", function() return Event("fx_loop") end))
  local r = emitCatch(s, Event("fx_head"))
  noProblems(API.rejection_problems(r, {
    code = "SISO_SELF_LOOP", eventId = 3, causeId = 1, eventType = "fx_loop",
  }))
end)

-- ── SISO_NOT_EVENT: the entry door ─────────────

test("SISO_NOT_EVENT at the entry: nothing is delivered", function()
  local delivered = false
  local s = Stream()
  s:register(Gate("x", function() delivered = true end))
  for _, v in ipairs({ "x", 42, false, true, {}, { type = "x", data = {} }, Gate("x") }) do
    local r = emitCatch(s, v)
    noProblems(API.rejection_problems(r, { code = "SISO_NOT_EVENT", noDelivery = true }), type(v))
  end
  assert(not delivered, "a refused entry reached a gate")
  assertEqual(#s:sampleHere().residue, 0)
end)

-- ── SISO_NOT_EVENT: gate returns ───────────────

test("SISO_NOT_EVENT for every return that is not nil, an Event or a list", function()
  local cases = {
    { "false", false },
    { "true", true },
    { "a number", 0 },
    { "a string", "fx_out" },
    { "a function", function() end },
    { "a plain table shaped like an event", { type = "fx_out", data = {} } },
    { "a list with a named key", { Event("fx_out"), extra = Event("fx_out") } },
    { "a list with a hole", { [1] = Event("fx_out"), [3] = Event("fx_out") } },
    { "a list starting at 2", { [2] = Event("fx_out") } },
    { "a list with a plain-table member", { Event("fx_out"), { type = "fx_out", data = {} } } },
    { "a list with a string member", { Event("fx_out"), "fx_out" } },
    { "a list with a false member", { false } },
    { "a list of lists", { { Event("fx_out") } } },
  }
  for _, c in ipairs(cases) do
    local s = Stream()
    s:emit(Event("before"))
    local before = s:sampleHere().residue[1]
    s:register(Gate("fx_feed", function() return Event("fx_bad") end))
    s:register(Gate("fx_bad", function() return c[2] end))
    local r = emitCatch(s, Event("fx_feed"))
    noProblems(API.rejection_problems(r, {
      code = "SISO_NOT_EVENT", eventId = 2, causeId = 1, eventType = "fx_bad",
    }), c[1])
    local residue = s:sampleHere().residue
    -- 12.0.1: OWNERSHIP — a sample / an observer record is the receiver's own copy, so compare values
    assert(#residue == 1 and residue[1].type == before.type and keysOf(residue[1].data) == keysOf(before.data),
      c[1] .. ": the residue changed")
  end
end)

test("accepted returns: nil, nothing, an Event, an empty list, a list", function()
  local cases = {
    { "nil", nil, "" },
    { "an Event", Event("fx_a"), "fx_a" },
    { "an empty list", {}, "" },
    { "a list", { Event("fx_a"), Event("fx_b") }, "fx_a,fx_b" },
  }
  for _, c in ipairs(cases) do
    local s = oneGate("fx_g", c[2])
    s:emit(Event("fx_g"))
    local t = {}
    for i, e in ipairs(s:takeResidue()) do t[i] = e.type end
    table.sort(t)
    assertEqual(table.concat(t, ","), c[3], c[1])
  end
  local s = Stream()
  s:register(Gate("fx_silent", function() end))
  s:emit(Event("fx_silent"))
  assertEqual(#s:takeResidue(), 0, "nothing returned")
end)

test("a list is judged in list order: the first bad member decides", function()
  local r1 = emitCatch(oneGate("fx_g", { Event("fx_ok"), 42, Event("fx_g") }), Event("fx_g"))
  assertEqual(r1.code, "SISO_NOT_EVENT", "non-Event before the self-loop")
  local r2 = emitCatch(oneGate("fx_g", { Event("fx_ok"), Event("fx_g"), 42 }), Event("fx_g"))
  assertEqual(r2.code, "SISO_SELF_LOOP", "self-loop before the non-Event")
  local r3 = emitCatch(oneGate("fx_g", { Event("fx_g"), extra = 1 }), Event("fx_g"))
  assertEqual(r3.code, "SISO_SELF_LOOP", "a member's self-loop before a named key")
end)

-- ── ids are per propagation ────────────────────

test("ids restart at 1 in each propagation, rejected ones included", function()
  local s = Stream()
  s:register(Gate("fx_a", function() return Event("fx_b") end))
  s:register(Gate("fx_b", function() error("boom") end))
  local first = emitCatch(s, Event("fx_a"))
  s:emit(Event("other"))
  s:emit(Event("other"))
  local again = emitCatch(s, Event("fx_a"))
  assertEqual(first.eventId, 2)
  assertEqual(again.eventId, 2, "nothing counted across propagations")
  assertEqual(s.eventCount, nil, "no counter on the stream")
end)

-- ── a rejected propagation leaves the stream as it was ──

test("a rejection leaves residue, gates and observers exactly as before", function()
  local s = Stream()
  s:emit(Event("kept", { v = 1 }))
  local before = s:sampleHere().residue
  s:register(Gate("fx_root", function() return { Event("fx_leftover"), Event("fx_x") } end))
  s:register(Gate("fx_x", function() return Event("fx_y") end))
  s:register(Gate("fx_y", function() return Event("fx_x") end))
  local batches = {}
  s:attach(function(b) batches[#batches + 1] = b end)
  local r = emitCatch(s, Event("fx_root"))
  assertEqual(r.code, "SISO_CYCLE")
  local after = s:sampleHere()
  assertEqual(#after.residue, 1)
  -- 12.0.1: OWNERSHIP — a sample / an observer record is the receiver's own copy, so compare values
  assert(after.residue[1].type == before[1].type and after.residue[1].data.v == before[1].data.v
    and keysOf(after.residue[1].data) == keysOf(before[1].data), "the same residue event")
  assertEqual(after.gateCount, 3)
  s:emit(Event("next"))
  assertEqual(#batches, 2, "the observer stays attached")
  assertEqual(batches[2].records[1].eventId, 1)
end)

-- ── the observe door ───────────────────────────

test("observer: records carry ids, causes, types, claims and the data received", function()
  local s = Stream()
  local given
  s:register(Gate("fx_root", function(e) given = e.data; return { Event("fx_mid"), Event("fx_out", { k = 2 }) } end))
  s:register(Gate("fx_mid", function() return Event("fx_end") end))
  local b
  s:attach(function(batch) b = batch end)
  s:emit(Event("fx_root", { k = 1 }))
  noProblems(SEAM.batch_problems(b))
  assertEqual(keysOf(b), "outcome,records")
  local shown = {}
  for i, r in ipairs(b.records) do
    shown[i] = r.eventId .. ":" .. tostring(r.causeId) .. ":" .. r.eventType .. ":" .. tostring(r.claimed)
  end
  assertEqual(table.concat(shown, " "),
    "1:nil:fx_root:true 2:1:fx_mid:true 3:1:fx_out:false 4:2:fx_end:false")
  -- 12.0.1: OWNERSHIP — a sample / an observer record is the receiver's own copy, so compare values
  assert(b.records[1].data.k == given.k and keysOf(b.records[1].data) == keysOf(given),
    "data is the table the gate received")
  assertEqual(b.records[3].data.k, 2)
end)

test("observer: a rejected batch ends with the named delivery and runs before the raise", function()
  local cases = {
    { "self-loop", function() return Event("fx_bad") end, "SISO_SELF_LOOP" },
    { "not an Event", function() return 7 end, "SISO_NOT_EVENT" },
    { "threw", function() error("x") end, "SISO_GATE_THREW" },
  }
  for _, c in ipairs(cases) do
    local s = Stream()
    s:emit(Event("kept"))
    s:register(Gate("fx_feed", function() return { Event("fx_side"), Event("fx_bad") } end))
    s:register(Gate("fx_bad", c[2]))
    local b, residueSeen
    s:attach(function(batch) b = batch; residueSeen = #s:sampleHere().residue end)
    local r = emitCatch(s, Event("fx_feed"))
    assertEqual(r.code, c[3], c[1])
    noProblems(SEAM.batch_problems(b), c[1])
    -- core 13 (observer-seam THE REJECTION): the observer's own read-only copy
    local why = SEAM.rejection_copy_problem(b.rejection, r)
    assert(why == nil, c[1] .. ": " .. tostring(why))
    assertEqual(#b.records, 3, c[1] .. ": records")
    assertEqual(b.records[3].eventType, "fx_bad", c[1])
    assertEqual(residueSeen, 1, c[1] .. ": nothing committed when the observer ran")
  end
end)

test("observer: a refused entry's batch carries the rejection and no records", function()
  local s = Stream()
  local b
  s:attach(function(batch) b = batch end)
  local r = emitCatch(s, { type = "x", data = {} })
  assertEqual(b.outcome, "rejected")
  local why = SEAM.rejection_copy_problem(b.rejection, r)
  assert(why == nil, "refused entry: " .. tostring(why))
  assertEqual(#b.records, 0)
end)

test("observer: one observer's writes to its records reach no other", function()
  local s = Stream()
  local seen
  s:attach(function(b) b.records[1].eventType = "changed"; b.records[1].claimed = "x" end)
  s:attach(function(b) seen = b end)
  s:emit(Event("q"))
  -- the order observers are called in carries no meaning, so run both orders
  local t = Stream()
  local seen2
  t:attach(function(b) seen2 = b end)
  t:attach(function(b) b.records[1].eventType = "changed" end)
  t:emit(Event("q"))
  assertEqual(seen.records[1].eventType, "q")
  assertEqual(seen.records[1].claimed, false)
  assertEqual(seen2.records[1].eventType, "q")
end)

test("observer: a raising observer leaves a rejection exactly as raised", function()
  local s = Stream()
  s:register(Gate("fx_t", function() error("gate failure") end))
  s:attach(function() error("observer failure") end)
  local r = emitCatch(s, Event("fx_t"))
  assertEqual(r.code, "SISO_GATE_THREW")
  assertEqual(r.cause:find("gate failure", 1, true) ~= nil, true)
end)

test("observer: an observer attached by another sees only later propagations", function()
  local s = Stream()
  local late = {}
  local function lateObserver(b) late[#late + 1] = b.records[1].eventType end
  local added = false
  s:attach(function()
    if not added then added = true; s:attach(lateObserver) end
  end)
  s:emit(Event("first"))
  s:emit(Event("second"))
  assertEqual(table.concat(late, ","), "second")
end)

test("observer: attach takes a function; misuse raises a string", function()
  local s = Stream()
  local ok, err = pcall(s.attach, s, {})
  assert(not ok and type(err) == "string", "attaching a table")
  local f = function() end
  s:attach(f)
  ok, err = pcall(s.attach, s, f)
  assert(not ok and type(err) == "string", "attaching twice")
  s:detach(f)
  ok, err = pcall(s.detach, s, f)
  assert(not ok and type(err) == "string", "detaching twice")
end)

H.finish("stream-doors")
