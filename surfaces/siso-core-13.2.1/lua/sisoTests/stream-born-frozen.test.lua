-- stream-born-frozen.test.lua — an event is read-only from the moment
-- Event builds it (core-api EVENT / READ-ONLY, core 13; phase doors-13).
-- Runs the core-api read-only check on the real core, then pins what it
-- leaves open: the refusal's message, defaults, identities kept, and that
-- the stream passes a born-frozen event through untouched. The gates
-- below are fixtures for the stream, not project gates.
--   lua5.4 sisoTests/stream-born-frozen.test.lua      (from the project root)
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local API = require("contracts.core_api")
local Event = require("core.event")
local Gate = require("core.gate")
local Stream = require("core.stream")
local freeze = require("core.freeze")

local core = { Event = Event, Gate = Gate, Stream = Stream }
local test, assert, assertEqual = H.test, H.assert, H.assertEqual

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

-- ── the contract on the real core ──────────────

test("core-api read-only holds on the core (from construction)", function()
  local p = API.check.readonly(core)
  assert(#p == 0, table.concat(p, "; "))
end)

test("core-api doors still hold on the core", function()
  local p = API.check.doors(core)
  assert(#p == 0, table.concat(p, "; "))
end)

-- ── what Event builds ──────────────────────────

test("a new event refuses assignment at every depth, with the read-only refusal", function()
  local e = Event("t", { v = 1, list = { 1, 2 }, m = { inner = { x = 5 } } })
  refuses("the type", function() e.type = "z" end)
  refuses("the data", function() e.data = {} end)
  refuses("a new field", function() e.extra = 1 end)
  refuses("a data field", function() e.data.v = 2 end)
  refuses("a new data key", function() e.data.added = 1 end)
  refuses("a list member", function() e.data.list[1] = 0 end)
  refuses("deep data", function() e.data.m.inner.x = 0 end)
  assertEqual(keysOf(e), "data,type")
  assertEqual(e.type .. ":" .. e.data.v .. ":" .. e.data.list[1] .. ":" .. e.data.m.inner.x, "t:1:1:5",
    "it reads back unchanged")
  assert(freeze.isFrozen(e) and freeze.isFrozen(e.data), "the event and its data are frozen")
  assert(Event.is(e), "Event.is recognises it")
end)

test("default data is an empty frozen table; extra arguments attach nothing", function()
  local d = Event("t")
  assertEqual(keysOf(d.data), "")
  refuses("default data", function() d.data.x = 1 end)
  local x = Event("t", { v = 1 }, { attempt = 1 }, "more")
  assertEqual(keysOf(x), "data,type")
  assertEqual(keysOf(x.data), "v")
end)

test("data is taken as it stands: later writes to the passed table do not reach the event", function()
  local orig = { v = 1, list = { 1 }, m = { k = "a" } }
  local e = Event("t", orig)
  orig.v = 2
  orig.list[1] = 9
  orig.m.k = "b"
  orig.late = true
  assertEqual(e.data.v, 1)
  assertEqual(e.data.list[1], 1)
  assertEqual(e.data.m.k, "a")
  assertEqual(e.data.late, nil)
  assert(not freeze.isFrozen(orig), "the caller's table stays its own")
  assertEqual(keysOf(orig), "late,list,m,v", "the caller's table carries nothing new")
end)

test("read-only data keeps its identity: frozen data, a carried event, a __readonly sentinel", function()
  local null = setmetatable({}, { __readonly = true, __newindex = function() error("immutable", 2) end })
  local a = Event("a", { v = 1 })
  local passed = Event("b", a.data)
  assert(rawequal(passed.data, a.data), "frozen data passed on is the same table")
  local carried = Event("c", { inner = a, d = a.data, n = null, list = { null } })
  assert(rawequal(carried.data.inner, a), "an event in data is that event")
  assert(rawequal(carried.data.d, a.data), "frozen data nested is that data")
  assert(rawequal(carried.data.n, null), "a sentinel nested keeps its identity")
  assert(rawequal(carried.data.list[1], null), "a sentinel in a list keeps its identity")
  assert(rawequal(Event("s", null).data, null), "a sentinel as the data itself keeps its identity")
end)

test("marks survive and sharing is kept; a cycle terminates (not plain data)", function()
  local mark = { __name = "fx.array" }
  local shared = { 1 }
  local e = Event("t", { list = setmetatable({ 1, 2 }, mark), plain = {}, a = shared, b = shared })
  assert(rawequal(getmetatable(e.data.list), mark), "a data mark survives")
  assertEqual(getmetatable(e.data.plain), false, "the proxy's own metatable is never handed out")
  assert(rawequal(e.data.a, e.data.b), "a table reached twice is one frozen table")
  local cyclic = { name = "c" }
  cyclic.self = cyclic
  local c = Event("cyc", cyclic).data
  assert(rawequal(c.self, c), "a cycle freezes to the same cycle")
  refuses("a write into a frozen cycle", function() c.self.name = "x" end)
end)

-- ── the stream with born-frozen events ─────────

test("entry takes the stream's own copy of a born-frozen event; a returned one passes untouched", function()
  -- 13.0.0 (core-api OWNERSHIP): entry copies, so the gate is given the
  -- stream's own event, equal in value; inside the propagation a gate's
  -- return is shared with the stream (R3) and keeps its identity.
  local entered = Event("fx_in", { v = 1, list = { 1 } })
  local returned
  local s = Stream()
  local given
  s:register(Gate("fx_in", function(e)
    given = e
    returned = Event("fx_out", { v = e.data.v })
    return returned
  end))
  s:emit(entered)
  assert(not rawequal(given, entered), "the gate is given the stream's own copy, not the entering code's event")
  assert(not rawequal(given.data, entered.data) and not rawequal(given.data.list, entered.data.list),
    "the copy shares no table with the entering code's event")
  assertEqual(given.type .. ":" .. given.data.v .. ":" .. given.data.list[1], "fx_in:1:1", "the copy reads the same")
  assert(Event.is(given) and freeze.isFrozen(given) and freeze.isFrozen(given.data), "the copy is a frozen event")
  local out = s:takeResidue()[1]
  assert(rawequal(out, returned), "the residue is the very event the gate built")
end)

test("an event a gate builds is read-only inside the gate, before it is returned", function()
  local s = Stream()
  local inGate
  s:register(Gate("fx_build", function()
    local out = Event("fx_out", { v = 1 })
    inGate = pcall(function() out.data.v = 2 end)
    return out
  end))
  s:emit(Event("fx_build"))
  assertEqual(inGate, false, "a write to a just-built event is refused in the gate")
  assertEqual(s:takeResidue()[1].data.v, 1)
end)

test("a gate writing its born-frozen input rejects as a throw, and commits nothing", function()
  local s = Stream()
  s:register(Gate("fx_writer", function(e) e.data.v = 2 end))
  local ok, r = pcall(s.emit, s, Event("fx_writer", { v = 1 }))
  assert(not ok, "a writing gate settled")
  local p = API.rejection_problems(r, { code = "SISO_GATE_THREW", eventId = 1, eventType = "fx_writer", entry = true })
  assert(#p == 0, table.concat(p, "; "))
  assert(tostring(r.cause):find("read-only", 1, true), "the cause is the refusal: " .. tostring(r.cause))
  assertEqual(#s:sampleHere().residue, 0)
end)

test("R3 concession: rawset on a born-frozen event is not refused (pinned so a change is seen)", function()
  local e = Event("t", { v = 1 })
  rawset(e.data, "v", 2)
  assertEqual(e.data.v, 2, "rawset bypasses the refusal (substrate concession R3)")
end)

H.finish("stream-born-frozen")
