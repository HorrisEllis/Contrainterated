-- stream-observers-entry.test.lua — phase observers-and-entry (core
-- 13.0.0). Every observer's batch is built before any observer is called
-- (observer-seam ISOLATION); an entry too deep to copy is refused as
-- SISO_NOT_EVENT, never raised raw (core-api: nothing else is raised);
-- an observer's plain rejection copy keeps string marks (THE
-- REJECTION). The gates below are fixtures for the stream, not project
-- gates.
--   lua5.4 sisoTests/stream-observers-entry.test.lua   (from the project root)
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local API = require("contracts.core_api")
local SEAM = require("contracts.observer_seam")
local Event = require("core.event")
local Gate = require("core.gate")
local Stream = require("core.stream")
local data = require("kit.data")

local core = { Event = Event, Gate = Gate, Stream = Stream }
local test, assert, assertEqual = H.test, H.assert, H.assertEqual

local function noProblems(list, label)
  assert(#list == 0, (label and label .. ": " or "") .. table.concat(list, "; "))
end

local function deepTable(depth)
  local t = {}
  local cur = t
  for _ = 1, depth do cur.x = {}; cur = cur.x end
  return t
end

-- ── the contracts on the real core ──────────────

test("core-api: ownership holds on the core (entry copy, deep entries)", function()
  noProblems(API.check.ownership(core))
end)

test("observer-seam: ownership and seam hold on the core", function()
  noProblems(SEAM.check.ownership(core), "ownership")
  noProblems(SEAM.check.seam(core), "seam")
end)

-- ── batches built before any observer is called ──

test("an observer that takes the residue and writes to it reaches no other observer (both orders)", function()
  for _, takerFirst in ipairs({ true, false }) do
    local s = Stream()
    s:register(Gate("a", function(e) return Event("b", { v = e.data.v, list = { 1 } }) end))
    local seen
    local function taker()
      for _, e in ipairs(s:takeResidue()) do
        rawset(e.data, "v", "TAKER WROTE")
        rawset(e.data.list, 1, "TAKER WROTE")
      end
    end
    local function reader(b) seen = b end
    if takerFirst then s:attach(taker); s:attach(reader) else s:attach(reader); s:attach(taker) end
    s:emit(Event("a", { v = 1 }))
    local label = takerFirst and "taker first" or "reader first"
    assertEqual(seen.records[1].data.v, 1, label .. ": entry record")
    assertEqual(seen.records[2].data.v, 1, label .. ": settled record")
    assertEqual(seen.records[2].data.list[1], 1, label .. ": nested data")
  end
end)

test("an observer that raises still leaves every other observer its batch", function()
  local s = Stream()
  local got = {}
  s:attach(function() error("first observer fails") end)
  s:attach(function(b) got[#got + 1] = b.outcome end)
  s:emit(Event("x"))
  assertEqual(table.concat(got, ","), "settled")
end)

test("a batch that cannot be built fails its observer alone: the rejection is raised all the same", function()
  local s = Stream()
  -- a cause whose __pairs raises: the plain copy cannot be built
  s:register(Gate("a", function()
    error(setmetatable({}, { __pairs = function() error("cannot be read") end }))
  end))
  local calls = 0
  s:attach(function() calls = calls + 1 end)
  local ok, r = pcall(s.emit, s, Event("a"))
  assert(not ok, "the gate's raise did not reject")
  assertEqual(r.code, "SISO_GATE_THREW")
  assertEqual(calls, 0, "the observer whose batch could not be built was called")
end)

-- ── an entry too deep to copy ───────────────────

test("an entry Event accepts but the stream cannot copy is SISO_NOT_EVENT, no delivery, nothing committed", function()
  local found = false
  for _, depth in ipairs({ 58000, 59000, 60000, 61000, 62000 }) do
    local okB, built = pcall(Event, "deep", deepTable(depth))
    if okB then
      local s = Stream()
      s:emit(Event("earlier"))
      local seen
      s:attach(function(b) seen = b end)
      local okE, r = pcall(s.emit, s, built)
      if not okE then
        assert(type(r) == "table", depth .. ": emit raised " .. tostring(r):sub(1, 80) .. ", not a rejection")
        noProblems(API.rejection_problems(r, { code = "SISO_NOT_EVENT", noDelivery = true }), depth .. "")
        assertEqual(#s:sampleHere().residue, 1, depth .. ": a refused entry committed residue")
        assert(seen and seen.outcome == "rejected" and #seen.records == 0, depth .. ": the observer's batch")
        found = true
      end
    end
  end
  -- on this substrate there is a window where Event accepts and copying
  -- overflows; if a future substrate has none, nothing here is wrong
  if not found then print("  (no depth in the window overflowed the copy on this substrate)") end
end)

test("a shallow entry is unaffected: copied, settled, the stream usable after a refused deep one", function()
  local s = Stream()
  pcall(s.emit, s, (select(2, pcall(Event, "deep", deepTable(61000)))))
  s:emit(Event("fine", { v = 1 }))
  local r = s:takeResidue()
  assertEqual(r[#r].type, "fine")
  assertEqual(r[#r].data.v, 1)
end)

-- ── plain rejection copies keep string marks ────

test("an observer's rejection keeps string marks: an array in a cause stays an array", function()
  local s = Stream()
  s:register(Gate("a", function() error({ rows = data.array({}), list = data.array({ 1, 2 }), n = data.null }) end))
  local shown
  s:attach(function(b) shown = b.rejection end)
  local _, raised = pcall(s.emit, s, Event("a"))
  local why = SEAM.rejection_copy_problem(shown, raised)
  assert(why == nil, tostring(why))
  assert(data.is_array(shown.cause.rows), "an empty array in the cause lost its mark")
  assert(data.is_array(shown.cause.list), "an array in the cause lost its mark")
  assert(rawequal(shown.cause.n, data.null), "data.null in the cause lost its identity")
  assertEqual(data.encode(shown.cause), data.encode(raised.cause))
  assertEqual(getmetatable(shown), false, "the copy itself answers false")
end)

test("an observer's rejection hands out no table through getmetatable, at any depth", function()
  local s = Stream()
  local Err = { __index = { kind = "io" } }
  s:register(Gate("a", function() error(setmetatable({ inner = setmetatable({}, Err) }, Err)) end))
  local shown
  s:attach(function(b) shown = b.rejection end)
  pcall(s.emit, s, Event("a"))
  local function walk(v, seen)
    if type(v) ~= "table" or seen[v] then return end
    seen[v] = true
    assert(type(getmetatable(v)) ~= "table", "a table answered getmetatable with a table")
    for _, x in pairs(v) do walk(x, seen) end
  end
  walk(shown, {})
end)

H.finish("stream-observers-entry")
