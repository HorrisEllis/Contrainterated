-- stream-rejection-copy.test.lua — each observer is shown its own
-- read-only copy of the rejection (observer-seam THE REJECTION, core 13;
-- phase doors-13), while the entering code receives the very table
-- raised, `cause` included. Runs the observer-seam checks on the real
-- core, then pins each code, the copies' independence, and that a copy
-- is built inside its observer's protected call. The gates below are
-- fixtures for the stream, not project gates.
--   lua5.4 sisoTests/stream-rejection-copy.test.lua   (from the project root)
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local API = require("contracts.core_api")
local SEAM = require("contracts.observer_seam")
local Event = require("core.event")
local Gate = require("core.gate")
local Stream = require("core.stream")

local core = { Event = Event, Gate = Gate, Stream = Stream }
local test, assert, assertEqual = H.test, H.assert, H.assertEqual

local function noProblems(list, label)
  assert(#list == 0, (label and label .. ": " or "") .. table.concat(list, "; "))
end
local function refuses(label, fn)
  local ok, err = pcall(fn)
  assert(not ok, label .. " was accepted")
  assert(tostring(err):find("read-only", 1, true), label .. ": raised " .. tostring(err))
end

-- Emit `entry` on `s` with `n` recording observers attached; → the raised
-- rejection (nil when it settled) and each observer's batches.
local function watched(s, entry, n)
  local seen = {}
  for i = 1, n do
    seen[i] = {}
    local into = seen[i]
    s:attach(function(b) into[#into + 1] = b end)
  end
  local ok, raised = pcall(s.emit, s, entry)
  return (not ok) and raised or nil, seen
end

-- ── the contract on the real core ──────────────

test("observer-seam: check.seam holds on the core", function()
  noProblems(SEAM.check.seam(core))
end)

test("observer-seam: check.ownership holds on the core (records and the rejection)", function()
  noProblems(SEAM.check.ownership(core))
end)

-- ── every code: a copy for the observer, the table itself for the host ──

local cases = {
  { "SISO_SELF_LOOP", function()
      local s = Stream()
      s:register(Gate("fx_loop", function() return Event("fx_loop") end))
      return s, Event("fx_loop")
    end },
  { "SISO_CYCLE", function()
      local s = Stream()
      s:register(Gate("fx_a", function() return Event("fx_b") end))
      s:register(Gate("fx_b", function() return Event("fx_a") end))
      return s, Event("fx_a")
    end },
  { "SISO_GATE_THREW", function()
      local s = Stream()
      s:register(Gate("fx_boom", function() error("boom") end))
      return s, Event("fx_boom")
    end },
  { "SISO_NOT_EVENT", function()
      local s = Stream()
      s:register(Gate("fx_bad", function() return 42 end))
      return s, Event("fx_bad")
    end },
  { "SISO_NOT_EVENT", function() return Stream(), { type = "not", data = {} } end },
}

test("each code: two observers each get their own read-only copy; the host gets the rejection itself", function()
  for i, c in ipairs(cases) do
    local code, build = c[1], c[2]
    local where = code .. " (case " .. i .. ")"
    local s, entry = build()
    local raised, seen = watched(s, entry, 2)
    assert(raised ~= nil, where .. ": settled")
    noProblems(API.rejection_problems(raised, { code = code }), where)
    assertEqual(tostring(raised), raised.message, where .. ": tostring of the raised rejection")
    assertEqual(#seen[1], 1, where .. ": observer 1 batches")
    assertEqual(#seen[2], 1, where .. ": observer 2 batches")
    local a, b = seen[1][1].rejection, seen[2][1].rejection
    local why = SEAM.rejection_copy_problem(a, raised) or SEAM.rejection_copy_problem(b, raised)
    assert(why == nil, where .. ": " .. tostring(why))
    assert(not rawequal(a, b), where .. ": the two observers share one copy")
    refuses(where .. ": assigning to a copy", function() a.code = "X" end)
    assertEqual(a.message, raised.message, where .. ": the copy's message is read by indexing")
  end
end)

test("SISO_GATE_THREW: the host's cause is exactly what the gate raised; each copy's cause is its own", function()
  local thrown = { "the gate's own", nested = { 1 }, message = "bad" }
  local s = Stream()
  s:register(Gate("fx_throw", function() error(thrown) end))
  local raised, seen = watched(s, Event("fx_throw"), 2)
  assert(rawequal(raised.cause, thrown), "the host receives the very table raised as cause")
  local a, b = seen[1][1].rejection, seen[2][1].rejection
  assert(not rawequal(a.cause, thrown) and not rawequal(b.cause, thrown), "a copy's cause is not the raised table")
  assert(not rawequal(a.cause, b.cause), "two copies share one cause")
  assert(not rawequal(a.cause.nested, thrown.nested), "a copy's nested cause is its own")
  assertEqual(a.cause[1], "the gate's own")
  assertEqual(a.cause.nested[1], 1)
  refuses("assigning into a copy's cause", function() a.cause.nested[1] = 9 end)
  rawset(a, "code", "CHANGED")
  rawset(a.cause, 1, "CHANGED")
  assertEqual(raised.code, "SISO_GATE_THREW", "a rawset on a copy reached the raised rejection")
  assertEqual(thrown[1], "the gate's own", "a rawset on a copy reached what the gate raised")
  assertEqual(b.code, "SISO_GATE_THREW", "a rawset on one copy reached another")
  assertEqual(b.cause[1], "the gate's own", "a rawset on one copy's cause reached another")
end)

test("a non-table cause is carried as it is", function()
  local s = Stream()
  s:register(Gate("fx_str", function() error("plain string", 0) end))
  local raised, seen = watched(s, Event("fx_str"), 1)
  assertEqual(raised.cause, "plain string")
  assertEqual(seen[1][1].rejection.cause, "plain string")
end)

test("a settled batch carries no rejection; with no observer the rejection is raised all the same", function()
  local s = Stream()
  local raised, seen = watched(s, Event("fx_free"), 1)
  assertEqual(raised, nil)
  assertEqual(seen[1][1].outcome, "settled")
  assertEqual(seen[1][1].rejection, nil)
  local t = Stream()
  t:register(Gate("fx_boom", function() error("boom") end))
  local ok, r = pcall(t.emit, t, Event("fx_boom"))
  assert(not ok)
  noProblems(API.rejection_problems(r, { code = "SISO_GATE_THREW", eventId = 1 }))
end)

-- ── the copy is built inside the observer's protected call ──

test("a copy that cannot be built fails that observer alone: emit raises exactly as without observers", function()
  -- A cause that answers `message` (read when the rejection is built) but
  -- raises on any other read, `pairs` included: copying it fails (13.0.0:
  -- the observer's copy is the cause's own fields, read through `pairs`).
  local poisoned = setmetatable({}, {
    __index = function(_, k)
      if k == "message" then return nil end
      error("poisoned read", 0)
    end,
    __pairs = function() error("poisoned pairs", 0) end,
  })
  local function run(n)
    local s = Stream()
    s:register(Gate("fx_poison", function() error(poisoned) end))
    local called = 0
    for _ = 1, n do s:attach(function() called = called + 1 end) end
    local ok, r = pcall(s.emit, s, Event("fx_poison"))
    local calledThen = called
    s:emit(Event("fx_after"))
    return ok, r, calledThen, #s:takeResidue()
  end
  local ok0, r0 = run(0)
  local ok2, r2, called, left = run(2)
  assert(not ok0 and not ok2, "the poisoned gate settled")
  assertEqual(r2.code, r0.code)
  assertEqual(r2.code, "SISO_GATE_THREW")
  assert(rawequal(r2.cause, poisoned), "the host still receives the very table raised")
  assertEqual(called, 0, "an observer whose batch could not be built is not called")
  assertEqual(left, 1, "the stream is usable afterwards and committed nothing from the rejection")
end)

H.finish("stream-rejection-copy")
