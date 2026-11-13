-- stream-freeze.test.lua — read-only events (core-api READ-ONLY, R3;
-- phase stream-freeze).
-- Runs the core-api read-only and doors checks and the observer-seam
-- check on the real core, then pins what they leave open, then measures
-- what freezing costs (printed, never asserted). The gates below are
-- fixtures for the stream, not project gates.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local API = require("contracts.core_api")
local SEAM = require("contracts.observer_seam")
local Event = require("core.event")
local Gate = require("core.gate")
local Stream = require("core.stream")
local freeze = require("core.freeze")

local core = { Event = Event, Gate = Gate, Stream = Stream }
local test, assert, assertEqual = H.test, H.assert, H.assertEqual

local function joined(list) return table.concat(list, "; ") end
local function noProblems(list, label)
  assert(#list == 0, (label and label .. ": " or "") .. joined(list))
end
local function refuses(label, fn)
  local ok, err = pcall(fn)
  assert(not ok, label .. " was accepted")
  assert(tostring(err):find("read-only", 1, true), label .. ": raised " .. tostring(err))
end
local function keysOf(t)
  local ks = {}
  for k in pairs(t) do ks[#ks + 1] = tostring(k) end
  table.sort(ks)
  return table.concat(ks, ",")
end

-- ── the contracts on the real core ─────────────

test("core-api read-only holds on the core", function()
  noProblems(API.check.readonly(core))
end)

test("core-api doors still hold on the core", function()
  noProblems(API.check.doors(core))
end)

test("observer-seam still holds on the core", function()
  noProblems(SEAM.check.seam(core))
end)

-- ── what a gate receives ───────────────────────

test("a gate cannot write its input, at any depth; the attempt rejects as a throw", function()
  local s = Stream()
  local seen = {}
  s:register(Gate("fx_probe", function(e)
    seen.type = pcall(function() e.type = "z" end)
    seen.data = pcall(function() e.data = {} end)
    seen.field = pcall(function() e.data.v = 2 end)
    seen.new = pcall(function() e.data.added = 1 end)
    seen.nested = pcall(function() e.data.list[1] = 0 end)
    seen.deep = pcall(function() e.data.m.inner.x = 0 end)
    seen.after = e.type .. ":" .. e.data.v .. ":" .. e.data.list[1] .. ":" .. e.data.m.inner.x
    seen.added = e.data.added
  end))
  s:emit(Event("fx_probe", { v = 1, list = { 1, 2 }, m = { inner = { x = 5 } } }))
  for _, k in ipairs({ "type", "data", "field", "new", "nested", "deep" }) do
    assertEqual(seen[k], false, "write to " .. k .. " refused")
  end
  assertEqual(seen.after, "fx_probe:1:1:5", "the input reads back unchanged")
  assertEqual(seen.added, nil)

  local t = Stream()
  t:register(Gate("fx_writer", function(e) e.data.v = 2 end))
  local ok, r = pcall(t.emit, t, Event("fx_writer", { v = 1 }))
  assert(not ok, "a writing gate settled")
  noProblems(API.rejection_problems(r, { code = "SISO_GATE_THREW", eventId = 1, eventType = "fx_writer" }))
  assert(tostring(r.cause):find("read-only", 1, true), "the cause is the refusal: " .. tostring(r.cause))
end)

test("reads: indexing, #, ipairs and pairs; the same field twice is the same value", function()
  local s = Stream()
  local got
  s:register(Gate("fx_read", function(e)
    local d = e.data
    local sum = 0
    for _, v in ipairs(d.list) do sum = sum + v end
    local f, state = pairs(d)
    got = {
      len = #d.list, second = d.list[2], sum = sum, keys = keysOf(d),
      eventKeys = keysOf(e), same = rawequal(d.m, d.m) and rawequal(e.data, e.data),
      missing = d.nope, stateIsProxy = rawequal(state, d), iter = type(f),
    }
  end))
  s:emit(Event("fx_read", { list = { 3, 4, 5 }, m = { k = 1 }, s = "x" }))
  assertEqual(got.len, 3)
  assertEqual(got.second, 4)
  assertEqual(got.sum, 12)
  assertEqual(got.keys, "list,m,s")
  assertEqual(got.eventKeys, "data,type")
  assert(got.same, "two reads of one table field are rawequal")
  assertEqual(got.missing, nil)
  assert(got.stateIsProxy, "pairs hands out the frozen table as its state, not something writable")
end)

-- ── what leaves through the doors ──────────────

test("a sample's and a take's events refuse writes; the stream is unchanged", function()
  local s = Stream()
  s:register(Gate("fx_a", function(e) return Event("fx_out", { v = e.data.v, list = { 1 } }) end))
  s:emit(Event("fx_a", { v = 1 }))
  local r = s:sampleHere().residue[1]
  refuses("a sampled event's type", function() r.type = "z" end)
  refuses("a sampled event's data", function() r.data = {} end)
  refuses("a sampled event's field", function() r.data.v = 9 end)
  refuses("a sampled event's nested list", function() r.data.list[1] = 9 end)
  assertEqual(s:sampleHere().residue[1].data.v, 1)
  local t = s:takeResidue()[1]
  -- 12.0.1: OWNERSHIP — a sample / an observer record is the receiver's own copy, so compare values
  assert(t.type == r.type and t.data.v == r.data.v and t.data.list[1] == r.data.list[1]
    and #t.data.list == #r.data.list, "the take hands out the same frozen event the sample showed")
  refuses("a taken event's field", function() t.data.v = 5 end)
  refuses("a taken event's new key", function() t.data.w = 5 end)
  assertEqual(t.data.v, 1)
end)

test("an observer's records' data refuse writes, settled and rejected", function()
  local s = Stream()
  s:register(Gate("fx_root", function() return Event("fx_mid", { k = { 2 } }) end))
  s:register(Gate("fx_mid", function() return Event("fx_mid") end))
  local batches = {}
  s:attach(function(b) batches[#batches + 1] = b end)
  pcall(s.emit, s, Event("fx_root", { k = { 1 } }))
  s:emit(Event("fx_other", { k = { 3 } }))
  assertEqual(#batches, 2)
  assertEqual(batches[1].outcome, "rejected")
  assertEqual(batches[2].outcome, "settled")
  for bi, b in ipairs(batches) do
    for ri, rec in ipairs(b.records) do
      local where = "batch " .. bi .. " record " .. ri
      refuses(where .. " data", function() rec.data.k = 0 end)
      refuses(where .. " nested data", function() rec.data.k[1] = 0 end)
    end
  end
  -- the batch and record tables are the observer's own and stay writable
  batches[2].records[1].eventType = "relabelled"
  assertEqual(batches[2].records[1].eventType, "relabelled")
end)

-- ── marks and sentinels ────────────────────────

test("kit-style marks survive: a frozen table answers its original's metatable", function()
  local mark = { __name = "fx.array" }
  local s = Stream()
  local inGate
  s:register(Gate("fx_mark", function(e)
    inGate = getmetatable(e.data.list)
    return Event("fx_out", { list = setmetatable({ 1, 2 }, mark), plain = {} })
  end))
  s:emit(Event("fx_mark", { list = setmetatable({}, mark) }))
  assert(rawequal(inGate, mark), "a gate sees its input's mark")
  local r = s:sampleHere().residue[1]
  assert(rawequal(getmetatable(r.data.list), mark), "an output's mark survives")
  assert(Event.is(r), "Event.is recognises a frozen event")
  assert(rawequal(getmetatable(r), getmetatable(Event("x"))), "a frozen event answers the event metatable")
  -- an original with no metatable: the proxy's own metatable is never handed out
  assertEqual(getmetatable(r.data.plain), false)
  assertEqual(getmetatable(r.data), false)
end)

test("a __readonly sentinel keeps its identity, nested or as the data itself", function()
  local null = setmetatable({}, { __readonly = true, __newindex = function() error("immutable", 2) end })
  local s = Stream()
  local inGate
  s:register(Gate("fx_null", function(e)
    inGate = e.data.n
    return { Event("fx_out", { n = null, list = { null } }), Event("fx_bare", null) }
  end))
  s:emit(Event("fx_null", { n = null }))
  assert(rawequal(inGate, null), "a gate reads the sentinel itself")
  local byType = {}
  for _, e in ipairs(s:takeResidue()) do byType[e.type] = e end
  assert(rawequal(byType.fx_out.data.n, null), "a nested sentinel keeps its identity")
  assert(rawequal(byType.fx_out.data.list[1], null), "a sentinel in a list keeps its identity")
  assert(rawequal(byType.fx_bare.data, null), "a sentinel as data keeps its identity")
end)

-- ── never wrapped twice ────────────────────────

test("an event passed on unchanged is not double-wrapped", function()
  -- residue taken and entered again: 13.0.0 (core-api OWNERSHIP) entry
  -- takes the stream's own copy, one frozen layer, not a wrapper of it
  local s = Stream()
  s:emit(Event("fx_kept", { v = 1 }))
  local taken = s:takeResidue()[1]
  assert(freeze.isFrozen(taken), "taken residue is frozen")
  s:emit(taken)
  local again = s:takeResidue()[1]
  assert(not rawequal(again, taken), "re-entered residue is the stream's own copy")
  assert(freeze.isFrozen(again) and Event.is(again), "the copy is a frozen event")
  assertEqual(again.type .. ":" .. again.data.v, "fx_kept:1")
  assert(rawequal(freeze(again), again) and rawequal(freeze(again.data), again.data),
    "the copy is frozen once: freezing it again gives it back")

  -- data passed on by a gate, and an event carried in data and returned
  local t = Stream()
  local given, carried
  t:register(Gate("fx_pass", function(e) given = e.data; return Event("fx_next", e.data) end))
  t:register(Gate("fx_unwrap", function(e) carried = e.data.inner; return e.data.inner end))
  t:emit(Event("fx_pass", { v = 1, list = { 1 } }))
  local out = t:takeResidue()[1]
  assert(rawequal(out.data, given), "data passed on is the same frozen table")
  t:emit(Event("fx_unwrap", { inner = Event("fx_inner", { w = 2 }) }))
  local inner = t:takeResidue()[1]
  assert(rawequal(inner, carried), "an event returned from data is the same frozen event")
  assertEqual(inner.data.w, 2)
  assert(rawequal(freeze(inner), inner), "freeze of a frozen table is that table")
end)

-- ── what freezing pins beyond the checks ───────

test("a frozen list a gate returns is judged as a list: carried, or rejected", function()
  local s = Stream()
  s:register(Gate("fx_fan", function(e) return e.data.out end))
  s:emit(Event("fx_fan", { out = { Event("fx_x"), Event("fx_y") } }))
  local types = {}
  for _, e in ipairs(s:takeResidue()) do types[#types + 1] = e.type end
  table.sort(types)
  assertEqual(table.concat(types, ","), "fx_x,fx_y", "both members carried, nothing dropped")
  local ok, r = pcall(s.emit, s, Event("fx_fan", { out = { Event("fx_x"), { type = "fake" } } }))
  assert(not ok, "a frozen list with a non-Event member settled")
  noProblems(API.rejection_problems(r, { code = "SISO_NOT_EVENT", eventId = 1, eventType = "fx_fan" }))
  ok, r = pcall(s.emit, s, Event("fx_fan", { out = { Event("fx_fan") } }))
  assert(not ok, "a frozen list with the gate's own type settled")
  assertEqual(r.code, "SISO_SELF_LOOP")
  ok, r = pcall(s.emit, s, Event("fx_fan", { out = { Event("fx_x"), named = 1 } }))
  assert(not ok, "a frozen table with a named key settled")
  assertEqual(r.code, "SISO_NOT_EVENT")
end)

test("freezing copies: the emitter's original stays its own and is never annotated", function()
  -- core 13: Event freezes what it builds, so the event itself refuses
  -- writes before it enters; the table passed to Event stays the caller's.
  local s = Stream()
  local original = { v = 1, list = { 1 } }
  local entered = Event("fx_kept", original)
  refuses("writing a built event's type", function() entered.type = "changed" end)
  s:emit(entered)
  original.v = 2
  original.list[1] = 9
  local r = s:sampleHere().residue[1]
  assertEqual(r.type, "fx_kept")
  assertEqual(r.data.v, 1)
  assertEqual(r.data.list[1], 1)
  assertEqual(keysOf(original), "list,v", "the original carries nothing new")
  assert(not freeze.isFrozen(original), "the caller's table is not frozen")
  assert(freeze.isFrozen(entered), "the event is frozen from construction")
end)

test("a table reached twice is one frozen table; a cycle terminates (not plain data)", function()
  local shared = { 1 }
  local cyclic = { name = "c" }
  cyclic.self = cyclic
  local s = Stream()
  s:emit(Event("fx_shared", { a = shared, b = shared }))
  s:emit(Event("fx_cycle", cyclic))
  local byType = {}
  for _, e in ipairs(s:takeResidue()) do byType[e.type] = e end
  assert(rawequal(byType.fx_shared.data.a, byType.fx_shared.data.b), "a shared table freezes once")
  local c = byType.fx_cycle.data
  assert(rawequal(c.self, c), "a cycle freezes to the same cycle")
  assertEqual(c.self.self.name, "c")
  refuses("a write into a frozen cycle", function() c.self.name = "x" end)
end)

test("R3 concession: rawset is not refused (pinned so a change to it is seen)", function()
  local s = Stream()
  s:emit(Event("fx_kept", { v = 1 }))
  local r = s:sampleHere().residue[1]
  rawset(r.data, "v", 2)
  assertEqual(r.data.v, 2, "rawset bypasses the refusal (substrate concession R3)")
end)

-- ── cost (printed, never asserted) ─────────────

-- The same event and stream modules with freezing (and the copies it
-- makes) replaced by the identity, for the baseline. Since core 13 Event
-- freezes too, so the baseline loads its own Event as well: a baseline
-- that kept the freezing Event would measure freezing on both arms.
-- Test-only: package.loaded is restored afterwards.
local function withoutFreeze()
  local saved = {}
  for _, m in ipairs({ "core.freeze", "core.event", "core.stream" }) do saved[m] = package.loaded[m] end
  local function same(v) return v end
  package.loaded["core.freeze"] = setmetatable({ isFrozen = function() return false end, copy = same, plain = same },
    { __call = function(_, v) return v end })
  package.loaded["core.event"], package.loaded["core.stream"] = nil, nil
  local PlainEvent = require("core.event")
  local PlainStream = require("core.stream")
  for m, v in pairs(saved) do package.loaded[m] = v end
  return PlainEvent, PlainStream
end

local function best(runs, fn)
  local min = math.huge
  for _ = 1, runs do
    collectgarbage("collect")
    local t0 = os.clock()
    fn()
    local dt = os.clock() - t0
    if dt < min then min = dt end
  end
  return min
end

test("the baseline arm freezes nothing, so the comparison measures freezing", function()
  local PlainEvent, PlainStream = withoutFreeze()
  local e = PlainEvent("fx_plain", { v = 1 })
  assert(not freeze.isFrozen(e) and not freeze.isFrozen(e.data), "the baseline Event does not freeze")
  e.data.v = 2
  assertEqual(e.data.v, 2, "the baseline event is writable")
  assert(PlainEvent.is(e), "the baseline Event still recognises its events")
  local s = PlainStream()
  s:emit(e)
  assert(not freeze.isFrozen(s:takeResidue()[1]), "the baseline stream does not freeze")
  assert(freeze.isFrozen(Event("fx_real")), "the real Event still freezes afterwards")
end)

test("cost of freezing (printed)", function()
  local PlainEvent, PlainStream = withoutFreeze()
  local plain = { Event = PlainEvent, Stream = PlainStream }
  local real = { Event = Event, Stream = Stream }
  local lines = {}

  local N = 20000
  local function chain(arm)
    local E, S = arm.Event, arm.Stream
    return function()
      local s = S()
      for i = 0, N - 1 do
        s:register(Gate("step_" .. i, function()
          if i < N - 1 then return E("step_" .. (i + 1), {}) end
          return nil
        end))
      end
      s:emit(E("step_0", {}))
    end
  end

  local W, HOPS = 10000, 10
  local wide = {}
  for i = 1, W do wide["k" .. i] = i end
  local function wideChain(arm, rebuild)
    local E, S = arm.Event, arm.Stream
    return function()
      local s = S()
      for i = 1, HOPS do
        s:register(Gate("w_" .. i, function(e)
          local d = e.data
          if rebuild then
            local copy = {}
            for k, v in pairs(d) do copy[k] = v end
            d = copy
          end
          return E("w_" .. (i + 1), d)
        end))
      end
      s:emit(E("w_1", wide))
    end
  end

  local function row(label, a, b)
    local ratio = a >= 0.001 and string.format("  (x%.2f)", b / a) or ""
    lines[#lines + 1] = string.format("  %-58s without %.4fs  with %.4fs%s", label, a, b, ratio)
  end
  row("20,000-gate chain (register + emit)", best(3, chain(plain)), best(3, chain(real)))
  row("10,000-entry data, 10-gate chain, data passed on", best(3, wideChain(plain, false)), best(3, wideChain(real, false)))
  row("10,000-entry data, 10-gate chain, each gate rebuilds data", best(3, wideChain(plain, true)), best(3, wideChain(real, true)))

  print("freeze cost (os.clock, best of 3):")
  for _, l in ipairs(lines) do print(l) end
end)

H.finish("stream-freeze")
