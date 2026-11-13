-- stream-ownership.test.lua — samples and observer records are their
-- receiver's own frozen copies (core-api OWNERSHIP, observer-seam RECORD
-- data; phase stream-ownership, retires X6).
-- Runs the core-api ownership, doors and read-only checks and the
-- observer-seam ownership and seam checks on the real core, then pins what
-- they leave open, then measures what the copies cost (printed, never
-- asserted). The gates below are fixtures for the stream, not project
-- gates.
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
local function isReadonly(t)
  local mt = getmetatable(t)
  return type(mt) == "table" and rawget(mt, "__readonly") == true
end

-- every table reachable from v through the read surface (pairs), as a
-- set; `__readonly` sentinels are left out (they are shared by design)
local function tablesOf(v, into)
  into = into or {}
  if type(v) ~= "table" or isReadonly(v) or into[v] then return into end
  into[v] = true
  for _, x in pairs(v) do tablesOf(x, into) end
  return into
end
-- the number of tables reachable from both a and b
local function shared(a, b)
  local sa, n = tablesOf(a), 0
  for t in pairs(tablesOf(b)) do if sa[t] then n = n + 1 end end
  return n
end

-- ── the contracts on the real core ─────────────

test("core-api ownership holds on the core", function()
  noProblems(API.check.ownership(core))
end)

test("core-api doors and read-only still hold on the core", function()
  noProblems(API.check.doors(core), "doors")
  noProblems(API.check.readonly(core), "readonly")
end)

test("observer-seam ownership and seam hold on the core", function()
  noProblems(SEAM.check.ownership(core), "ownership")
  noProblems(SEAM.check.seam(core), "seam")
end)

-- ── samples ────────────────────────────────────

local function deep()
  return { v = 1, a = { b = { c = { d = { e = { leaf = 1, list = { 1, 2, 3 } } } } } } }
end

test("deep nesting: a rawset at any depth of a sample reaches nothing; no proxy is shared", function()
  local s = Stream()
  s:emit(Event("fx_deep", deep()))
  local a = s:sampleHere().residue[1]
  local e = a.data.a.b.c.d.e
  rawset(e, "leaf", 9)
  rawset(e.list, 2, 9)
  rawset(e, "added", 1)
  rawset(a.data.a.b, "c", "gone")
  rawset(a, "data", {})
  local b = s:sampleHere().residue[1]
  local eb = b.data.a.b.c.d.e
  assertEqual(eb.leaf, 1, "deep field")
  assertEqual(eb.list[2], 2, "deep list member")
  assertEqual(eb.added, nil, "deep added key")
  assertEqual(#eb.list, 3)
  local t = s:takeResidue()[1]
  assertEqual(t.data.a.b.c.d.e.leaf, 1, "the taken residue is untouched")
  assertEqual(shared(b, t), 0, "a sample shares no table with the residue it copies")
  refuses("a deep write into a copy", function() eb.list[1] = 0 end)
end)

test("copies read like the original: indexing, #, ipairs, pairs; the same field twice is the same value", function()
  local s = Stream()
  s:emit(Event("fx_read", { list = { 3, 4, 5 }, m = { k = 1 }, s = "x" }))
  local r = s:sampleHere().residue[1]
  assert(Event.is(r), "Event.is recognises a copied event")
  assert(freeze.isFrozen(r) and freeze.isFrozen(r.data.m), "a copy is frozen")
  assert(rawequal(r.data, r.data) and rawequal(r.data.m, r.data.m), "two reads give the same table")
  assertEqual(#r.data.list, 3)
  local sum = 0
  for _, v in ipairs(r.data.list) do sum = sum + v end
  assertEqual(sum, 12)
  local keys = {}
  for k in pairs(r.data) do keys[#keys + 1] = k end
  table.sort(keys)
  assertEqual(table.concat(keys, ","), "list,m,s")
  refuses("a copied event's type", function() r.type = "z" end)
  refuses("a copied event's field", function() r.data.s = "y" end)
  refuses("a copied event's new key", function() r.data.w = 1 end)
end)

test("marks and __readonly sentinels go through copies; no metatable stays none", function()
  local mark = { __name = "fx.array" }
  local null = setmetatable({}, { __readonly = true, __newindex = function() error("immutable", 2) end })
  local s = Stream()
  s:register(Gate("fx_mark", function()
    return { Event("fx_out", { list = setmetatable({ 1, null }, mark), n = null, plain = {} }),
             Event("fx_bare", null) }
  end))
  s:emit(Event("fx_mark"))
  local byType = {}
  for _, e in ipairs(s:sampleHere().residue) do byType[e.type] = e end
  local d = byType.fx_out.data
  assert(rawequal(getmetatable(d.list), mark), "a mark survives a copy")
  assert(rawequal(getmetatable(byType.fx_out), getmetatable(Event("x"))), "a copied event answers the event metatable")
  assertEqual(getmetatable(d), false, "a copy of data with no metatable has none")
  assertEqual(getmetatable(d.plain), false)
  assert(rawequal(d.n, null), "a nested sentinel keeps its identity")
  assert(rawequal(d.list[2], null), "a sentinel in a list keeps its identity")
  assert(rawequal(byType.fx_bare.data, null), "a sentinel as data keeps its identity")
  -- and through freeze.copy directly, a copy of a copy
  local again = freeze.copy(d)
  assert(rawequal(getmetatable(again.list), mark) and getmetatable(again) == false, "a copy of a copy keeps marks")
  assert(rawequal(again.n, null))
  assertEqual(shared(again, d), 0, "a copy of a copy shares nothing with it")
end)

test("two samples are independent", function()
  local s = Stream()
  s:emit(Event("fx_x", { v = 1, n = { 1 } }))
  s:emit(Event("fx_y", { w = { k = "a" } }))
  local a = s:sampleHere().residue
  local b = s:sampleHere().residue
  assertEqual(#a, 2)
  for i = 1, 2 do
    assert(not rawequal(a[i], b[i]), "event " .. i .. " is a separate copy in each sample")
    assertEqual(shared(a[i], b[i]), 0, "event " .. i .. ": the samples share no table")
  end
  for _, e in ipairs(a) do
    rawset(e, "type", "z")
    for k in pairs(e.data) do rawset(e.data, k, "changed") end
  end
  local types = {}
  for i, e in ipairs(b) do types[i] = e.type end
  table.sort(types)
  assertEqual(table.concat(types, ","), "fx_x,fx_y", "the other sample's events")
  for _, e in ipairs(s:sampleHere().residue) do
    if e.type == "fx_x" then assertEqual(e.data.n[1], 1) else assertEqual(e.data.w.k, "a") end
  end
end)

test("sharing inside one sample is kept, never with the stream; a cycle terminates", function()
  local s = Stream()
  s:register(Gate("fx_fan", function(e) return { Event("fx_l", e.data), Event("fx_r", e.data) } end))
  s:emit(Event("fx_fan", { list = { 1 } }))
  local cyclic = { name = "c" }
  cyclic.self = cyclic
  s:emit(Event("fx_cycle", cyclic))
  local byType = {}
  for _, e in ipairs(s:sampleHere().residue) do byType[e.type] = e end
  assert(rawequal(byType.fx_l.data, byType.fx_r.data), "data shared in the residue is one copy in one sample")
  local c = byType.fx_cycle.data
  assert(rawequal(c.self, c), "a cycle copies to the same cycle")
  assertEqual(c.self.self.name, "c")
  local held = {}
  for _, e in ipairs(s:takeResidue()) do held[e.type] = e end
  assert(not rawequal(byType.fx_l.data, held.fx_l.data), "the sample's data is not the stream's")
  assertEqual(shared(byType.fx_cycle, held.fx_cycle), 0)
end)

test("take hands over the stream's own events; entering them again takes a fresh copy", function()
  -- 13.0.0 (core-api OWNERSHIP): entry copies, so the taker's handle never
  -- reaches the residue, taken residue entered again included
  local s = Stream()
  s:emit(Event("fx_kept", { v = 1, n = { 1 } }))
  local before = s:sampleHere().residue[1]
  local taken = s:takeResidue()[1]
  assertEqual(#s:sampleHere().residue, 0, "the stream holds none of what it handed over")
  s:emit(taken)
  rawset(taken.data, "v", 7)
  rawset(taken.data.n, 1, 9)
  local again = s:takeResidue()[1]
  assert(not rawequal(again, taken), "re-entered residue is the stream's own copy")
  assertEqual(shared(again, taken), 0, "the copy shares no table with the taker's event")
  assertEqual(again.data.v, 1, "a rawset on the taker's event did not reach the residue")
  assertEqual(again.data.n[1], 1)
  assertEqual(before.data.v, 1)
end)

test("the entering code's own event never reaches the residue, at any depth", function()
  local held = Event("fx_held", deep())
  local s = Stream()
  s:emit(held)
  rawset(held, "type", "z")
  rawset(held.data.a.b.c.d.e, "leaf", 9)
  rawset(held.data.a.b.c.d.e.list, 1, 9)
  local r = s:takeResidue()[1]
  assertEqual(r.type, "fx_held")
  assertEqual(r.data.a.b.c.d.e.leaf, 1)
  assertEqual(r.data.a.b.c.d.e.list[1], 1)
  assertEqual(shared(r, held), 0, "the residue shares no table with the entering code's event")
end)

-- ── observers ──────────────────────────────────

test("each observer's records carry its own copies; within one observer data passed on is one copy", function()
  local s = Stream()
  local given
  s:register(Gate("fx_root", function(e) given = e.data; return Event("fx_next", e.data) end))
  local A, B
  s:attach(function(b) A = b end)
  s:attach(function(b) B = b end)
  s:emit(Event("fx_root", { v = 1, n = { 1 } }))
  local held = s:sampleHere().residue[1]
  local taken = s:takeResidue()[1]
  assert(rawequal(taken.data, given), "inside the propagation the data is shared (R3), and the residue holds it")
  assert(not rawequal(A.records[1].data, given), "a record is not the data the gate received")
  assertEqual(shared(A.records[1].data, given), 0, "a record shares no table with the stream's data")
  assertEqual(shared(A.records[1].data, B.records[1].data), 0, "two observers share no table")
  assert(rawequal(A.records[1].data, A.records[2].data), "data passed on is one copy for one observer")
  assertEqual(A.records[2].data.n[1], 1)
  assertEqual(held.data.v, 1)
  refuses("an observer's record data", function() A.records[1].data.v = 2 end)
end)

test("a rejected batch's records are copies too", function()
  for _, writerFirst in ipairs({ true, false }) do
    local s = Stream()
    local given
    s:register(Gate("fx_root", function(e) given = e.data; return Event("fx_mid", e.data) end))
    s:register(Gate("fx_mid", function() error("boom") end))
    local seen
    local function writer(b)
      for _, r in ipairs(b.records) do
        rawset(r.data, "v", 99)
        rawset(r.data.n, 1, 9)
      end
    end
    local function reader(b) seen = b end
    if writerFirst then s:attach(writer); s:attach(reader) else s:attach(reader); s:attach(writer) end
    local ok, r = pcall(s.emit, s, Event("fx_root", { v = 1, n = { 1 } }))
    assert(not ok and r.code == "SISO_GATE_THREW", "the propagation is rejected")
    assertEqual(seen.outcome, "rejected")
    assertEqual(#seen.records, 2)
    for i, rec in ipairs(seen.records) do
      assertEqual(rec.data.v, 1, "record " .. i .. ": another observer's rawset did not reach it")
      assertEqual(rec.data.n[1], 1)
      assertEqual(shared(rec.data, given), 0, "record " .. i .. " shares no table with the stream's data")
    end
    assertEqual(rawget(given, "v"), nil, "the stream's frozen data carries no raw field")
  end
end)

test("the copy reads what a reader reads: a raw field a gate wrote past the refusal (R3)", function()
  local s = Stream()
  s:register(Gate("fx_raw", function(e)
    local d = e.data
    rawset(d, "v", 2) -- the substrate concession R3, inside the propagation
    return Event("fx_out", d)
  end))
  local b
  s:attach(function(batch) b = batch end)
  s:emit(Event("fx_raw", { v = 1 }))
  assertEqual(b.records[2].data.v, 2, "a record reads the field as indexing does")
  local r = s:sampleHere().residue[1]
  assertEqual(r.data.v, 2, "a sample reads the field as indexing does")
  assertEqual(rawget(r.data, "v"), nil, "in the copy it is part of the frozen view, not a raw field")
  refuses("that field in a copy", function() r.data.v = 3 end)
end)

-- ── copying only when needed ───────────────────

-- The same stream module with freeze.copy counted, or replaced by the
-- identity (the sharing variant, as before this phase). Test-only:
-- package.loaded is restored afterwards.
local function streamWithCopy(copyFn)
  local savedFreeze, savedStream = package.loaded["core.freeze"], package.loaded["core.stream"]
  package.loaded["core.freeze"] = setmetatable({ isFrozen = freeze.isFrozen, copy = copyFn, plain = freeze.plain },
    { __call = function(_, v) return freeze(v) end })
  package.loaded["core.stream"] = nil
  local Variant = require("core.stream")
  package.loaded["core.freeze"], package.loaded["core.stream"] = savedFreeze, savedStream
  return Variant
end

test("no observer means no copying past the entry; one copy per record per observer, one per sampled event", function()
  -- 13.0.0: entry takes the stream's own copy, one copy per emit
  local calls = 0
  local Counted = streamWithCopy(function(v, memo) calls = calls + 1; return freeze.copy(v, memo) end)
  local function chain()
    local s = Counted()
    s:register(Gate("fx_a", function() return { Event("fx_b", { k = 1 }), Event("fx_c") } end))
    s:register(Gate("fx_b", function() return Event("fx_d") end))
    return s
  end
  local s = chain()
  local ok, r = pcall(s.emit, s, Event("fx_a", { k = 0 }))
  assert(ok, tostring(r))
  local t = chain()
  t:register(Gate("fx_d", function() error("boom") end))
  pcall(t.emit, t, Event("fx_a"))
  assertEqual(calls, 2, "no observer attached, settled or rejected: only each entry is copied")
  calls = 0
  pcall(t.emit, t, "not an event")
  assertEqual(calls, 0, "a refused entry copies nothing")
  local u = chain()
  u:attach(function() end)
  u:attach(function() end)
  u:emit(Event("fx_a"))
  assertEqual(calls, 1 + 2 * 4, "the entry, then two observers, four records each")
  local v = chain()
  v:emit(Event("fx_a"))
  calls = 0
  local n = #v:sampleHere().residue
  assertEqual(n, 2)
  assertEqual(calls, n, "a sample copies each residue event once")
  calls = 0
  v:takeResidue()
  assertEqual(calls, 0, "a take copies nothing")
end)

-- ── cost (printed, never asserted) ─────────────

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

test("cost of the copies (printed)", function()
  local Sharing = streamWithCopy(function(v) return v end)
  local lines = {}
  local function row(label, a, b)
    local ratio = a >= 0.0001 and string.format("  (x%.2f)", b / a) or ""
    lines[#lines + 1] = string.format("  %-52s sharing %.4fs  copies %.4fs%s", label, a, b, ratio)
  end

  -- sampleHere on 2,000 residue events, each with 10-entry data
  local function filled(S)
    local s = S()
    for i = 1, 2000 do
      local d = {}
      for j = 1, 10 do d["k" .. j] = i * 10 + j end
      s:emit(Event("fx_r" .. i, d))
    end
    return s
  end
  local sp, sc = filled(Sharing), filled(Stream)
  row("sampleHere, 2,000 events x 10-entry data", best(3, function() sp:sampleHere() end),
    best(3, function() sc:sampleHere() end))

  -- a 20,000-gate chain with one observer attached
  local N = 20000
  local function chain(S)
    return function()
      local s = S()
      for i = 0, N - 1 do
        s:register(Gate("step_" .. i, function()
          if i < N - 1 then return Event("step_" .. (i + 1), {}) end
          return nil
        end))
      end
      s:attach(function() end)
      s:emit(Event("step_0", {}))
    end
  end
  row("20,000-gate chain, one observer (register + emit)", best(3, chain(Sharing)), best(3, chain(Stream)))

  print("ownership cost (os.clock, best of 3):")
  for _, l in ipairs(lines) do print(l) end
end)

H.finish("stream-ownership")
