-- stream-stateless.test.lua — the doors carry no state (core-api
-- STATELESS THROUGH THE EVENT, IMMUTABLE SENTINELS, OWNERSHIP;
-- observer-seam THE REJECTION; core 13.0.0, phase stateless-doors).
-- Nothing reachable from an event, a sample, a take or an observer's
-- batch may be a table the core holds or shares. Runs the contracts'
-- checks on the real core, then walks everything a holder can reach
-- without `debug` (getmetatable, the raw `next` surface, `pairs` and the
-- iterator state it hands out) and pins that it is fresh, immutable and
-- shared with no other stream or propagation. The gates below are
-- fixtures for the stream, not project gates.
--   lua5.4 sisoTests/stream-stateless.test.lua   (from the project root)
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local API = require("contracts.core_api")
local SEAM = require("contracts.observer_seam")
local Event = require("core.event")
local Gate = require("core.gate")
local Stream = require("core.stream")
local Rejection = require("core.rejection")
local freeze = require("core.freeze")

local core = { Event = Event, Gate = Gate, Stream = Stream }
local test, assert, assertEqual = H.test, H.assert, H.assertEqual

local function noProblems(list, label)
  assert(#list == 0, (label and label .. ": " or "") .. table.concat(list, "; "))
end

-- an immutable sentinel in the string form (IMMUTABLE SENTINELS)
local function sentinel(name)
  return setmetatable({}, {
    __metatable = "readonly:" .. name,
    __newindex = function() error("immutable", 2) end,
  })
end
local function isStringSentinel(v)
  local mt = getmetatable(v)
  return type(mt) == "string" and mt:sub(1, 9) == "readonly:"
end

--[[
reach(roots) → the set of tables reachable from roots by anything a holder
can do without `debug`: getmetatable (followed when it answers a table),
the raw surface (`next`, so a raw field written past the refusal counts),
and `pairs`, including the state it hands out. "readonly:" sentinels are
immutable values shared by design: they are not entered.
]]
local function reach(roots)
  local seen = {}
  local function visit(v)
    if type(v) ~= "table" or seen[v] or isStringSentinel(v) then return end
    seen[v] = true
    local mt = getmetatable(v)
    if type(mt) == "table" then visit(mt) end
    for k, x in next, v do visit(k); visit(x) end
    local ok, f, s, init = pcall(pairs, v)
    if ok then
      visit(s)
      pcall(function() for k, x in f, s, init do visit(k); visit(x) end end)
    end
  end
  for _, r in ipairs(roots) do visit(r) end
  return seen
end
local function count(set)
  local n = 0
  for _ in pairs(set) do n = n + 1 end
  return n
end
local function overlap(a, b)
  local n = 0
  for t in pairs(a) do if b[t] then n = n + 1 end end
  return n
end

--[[
scenario() → everything that left one fresh stream through its doors in
a settled and a rejected propagation: the events its gates were given,
two samples, the take, and an observer's batches (records' data and the
rejection copy). Each call builds its own stream, gates and entries.
]]
local function scenario()
  local out = {}
  local function keep(v) out[#out + 1] = v end
  local null = sentinel("stateless")
  local s = Stream()
  s:register(Gate("fx_root", function(e)
    keep(e)
    return { Event("fx_mid", { list = { e.data.v, 2 }, m = { k = "a" }, n = null }), Event("fx_leaf", e.data) }
  end))
  s:register(Gate("fx_mid", function(e) keep(e); return Event("fx_out", { from = e.data }) end))
  s:register(Gate("fx_bad", function(e) keep(e); error({ "the gate's own", nested = { 1 }, n = null }) end))
  local batches = {}
  s:attach(function(b) batches[#batches + 1] = b end)
  s:emit(Event("fx_root", { v = 1, deep = { a = { b = { 1 } } } }))
  pcall(s.emit, s, Event("fx_bad", { w = { 1 } }))
  keep(s:sampleHere())
  keep(s:sampleHere())
  keep(s:takeResidue())
  for _, b in ipairs(batches) do keep(b) end
  return out, batches
end

-- ── the contracts on the real core ─────────────

test("core-api read-only, ownership and doors hold on the core", function()
  noProblems(API.check.readonly(core), "readonly")
  noProblems(API.check.ownership(core), "ownership")
  noProblems(API.check.doors(core), "doors")
end)

test("observer-seam seam and ownership hold on the core", function()
  noProblems(SEAM.check.seam(core), "seam")
  noProblems(SEAM.check.ownership(core), "ownership")
end)

-- ── identities answer with strings ─────────────

test("events answer \"siso.event\", plain data false, rejections \"siso.rejection\"", function()
  local e = Event("t", { list = { 1 }, m = {} })
  assertEqual(getmetatable(e), "siso.event")
  assertEqual(getmetatable(e.data), false)
  assertEqual(getmetatable(e.data.list), false)
  assertEqual(getmetatable(Event("u").data), false, "default data")
  assert(Event.is(e) and not Event.is({ type = "t", data = {} }), "Event.is compares the string")
  local s = Stream()
  s:register(Gate("fx_boom", function() error("boom") end))
  local _, r = pcall(s.emit, s, Event("fx_boom"))
  assertEqual(getmetatable(r), "siso.rejection")
  assert(Rejection.is(r), "Rejection.is compares the string")
  assertEqual(tostring(r), r.message, "tostring of the raised rejection is still its message")
  assert(not pcall(function() getmetatable(r).x = 1 end), "nothing writable answers getmetatable on a rejection")
end)

-- ── nothing reachable is shared ────────────────

test("two streams share no table through anything that left their doors", function()
  local a = reach((scenario()))
  local b = reach((scenario()))
  assert(count(a) > 20 and count(b) > 20, "the walk reached the outputs (" .. count(a) .. ", " .. count(b) .. ")")
  assertEqual(overlap(a, b), 0, "tables reachable from both streams")
end)

test("two events, and two propagations of one stream, share no table", function()
  assertEqual(overlap(reach({ Event("t", { a = { 1 } }) }), reach({ Event("t", { a = { 1 } }) })), 0, "two events")
  local s = Stream()
  local given = {}
  s:register(Gate("fx_g", function(e) given[#given + 1] = e; return Event("fx_out", { got = e.data }) end))
  local entry = Event("fx_g", { v = { 1 } })
  s:emit(entry)
  local first = { given[1], s:takeResidue() }
  s:emit(entry)
  local second = { given[2], s:takeResidue() }
  assertEqual(overlap(reach(first), reach(second)), 0, "one entry entered twice")
  assertEqual(overlap(reach({ entry }), reach(first)), 0, "the entering code's event and the stream's")
end)

test("plain data reaches only immutable values: every reachable table refuses assignment and hands out no table", function()
  local out, batches = scenario()
  local own = {}  -- the receiver's own containers: lists and batch tables
  for _, v in ipairs(out) do own[v] = true end
  for _, b in ipairs(batches) do
    own[b.records] = true
    for _, r in ipairs(b.records) do own[r] = true end
  end
  for _, v in ipairs(out) do if type(v.residue) == "table" then own[v.residue] = true end end
  local checked = 0
  for t in pairs(reach(out)) do
    if not own[t] then
      checked = checked + 1
      local mt = getmetatable(t)
      assert(mt == false or mt == "siso.event", "a reachable table answers getmetatable with " .. tostring(mt))
      assert(not pcall(function() t.__probe = 1 end), "a reachable table accepts assignment")
    end
  end
  assert(checked > 20, "the walk checked the frozen tables (" .. checked .. ")")
end)

test("a gate writing (rawset included) into everything it can reach counts nothing across propagations or streams", function()
  local function run(s)
    s:emit(Event("fx_count", { a = { b = { 1 } }, l = { 1, 2 } }))
    return s:takeResidue()[1].data.n
  end
  local function counting()
    local s = Stream()
    s:register(Gate("fx_count", function(e)
      local most = 0
      for t in pairs(reach({ e })) do
        local n = (rawget(t, "__probe") or 0) + 1
        rawset(t, "__probe", n)
        if n > most then most = n end
      end
      return Event("fx_counted", { n = most })
    end))
    return s
  end
  local s = counting()
  assertEqual(run(s), 1, "first propagation")
  assertEqual(run(s), 1, "a second propagation of the same stream")
  assertEqual(run(counting()), 1, "another stream")
end)

-- ── a captured freeze key carries no state (accepted, R3) ──

-- Capture the key freeze recognises its proxies by: a table with an
-- active __index (not plain data) is asked for it. → the key, or nil.
local function captureKey(e)
  local got
  local spy = setmetatable({}, { __index = function(_, k) if type(k) ~= "string" then got = k end end })
  local f = pairs(e.data)
  pcall(f, spy, nil)            -- the pairs step asks the table it is given
  if got == nil then Event("fx_spy", { s = spy }) end  -- freezing asks a table with a metatable
  return got
end

test("pairs hands out the frozen table itself as its state; the step reads its own proxy", function()
  local e = Event("t", { a = 1 })
  local f, st, init = pairs(e.data)
  assert(rawequal(st, e.data), "pairs hands out the frozen table itself as its state")
  assertEqual(f(st, init), "a")
  assert(not pcall(f, {}, nil), "the step given a plain table raises")
end)

test("a captured key is a function that holds no field; the view it reaches answers false", function()
  local e = Event("t", { v = 1, list = { 1 } })
  local key = captureKey(e)
  assertEqual(type(key), "function", "the captured key")
  assert(not pcall(function() key.x = 1 end), "the key accepts a field")
  local view = e.data[key]
  assert(type(view) == "table" and not rawequal(view, e.data), "the key reaches the view")
  assertEqual(getmetatable(view), false, "a view hands out no metatable")
  assert(rawequal(view[key], view), "the view answers itself for the key")
  assertEqual(overlap(reach({ view }), reach({ Event("t", { v = 1, list = { 1 } }).data[key] })), 0,
    "two views share no table")
  -- a write through the view is a write past the refusal on that one table (R3)
  view.v = 2
  assertEqual(e.data.v, 2, "a write through the view reaches that table, as rawset does")
end)

test("nothing written through a captured key reaches another propagation, stream, sample or observer", function()
  local function counting()
    local s = Stream()
    s:register(Gate("fx_count", function(e)
      local key = captureKey(e)
      local most = 0
      local tables = reach({ e })
      for t in pairs(reach({ e })) do
        local ok, view = pcall(function() return t[key] end)
        if ok and type(view) == "table" then tables[view] = true end
      end
      for t in pairs(tables) do
        local n = (rawget(t, "__probe") or 0) + 1
        rawset(t, "__probe", n)
        if n > most then most = n end
      end
      return Event("fx_counted", { n = most })
    end))
    return s
  end
  local function run(s)
    s:emit(Event("fx_count", { a = { b = { 1 } }, l = { 1, 2 } }))
    return s:takeResidue()[1].data.n
  end
  local s = counting()
  assertEqual(run(s), 1, "first propagation")
  assertEqual(run(s), 1, "a second propagation of the same stream")
  assertEqual(run(counting()), 1, "another stream")

  -- the outside, through a captured key: a sample's and a record's views
  local t = Stream()
  local batches = {}
  t:attach(function(b) batches[#batches + 1] = b end)
  t:attach(function(b) batches[#batches + 1] = b end)
  t:emit(Event("fx_kept", { v = 1 }))
  local sampled = t:sampleHere().residue[1]
  local key = captureKey(sampled)
  sampled.data[key].v = 9
  batches[1].records[1].data[key].v = 8
  assertEqual(t:sampleHere().residue[1].data.v, 1, "a write through a sample's view reached the stream")
  assertEqual(batches[2].records[1].data.v, 1, "a write through one observer's view reached another")
  assertEqual(t:takeResidue()[1].data.v, 1, "a write through a view reached the residue taken")
end)

-- ── immutable sentinels ────────────────────────

test("a \"readonly:\" sentinel keeps its identity through every door; the core 12 form is not handed to observers", function()
  local null = sentinel("kit.data.null")
  local s = Stream()
  local inGate
  s:register(Gate("fx_null", function(e) inGate = e.data.n; return Event("fx_out", { n = e.data.n, l = { null } }) end))
  s:register(Gate("fx_throw", function(e) error({ n = e.data.n }) end))
  local batches = {}
  s:attach(function(b) batches[#batches + 1] = b end)
  s:emit(Event("fx_null", { n = null }))
  local _, raised = pcall(s.emit, s, Event("fx_throw", { n = null }))
  assert(rawequal(inGate, null), "a gate is given the sentinel itself, through the entry copy")
  local sampled = s:sampleHere().residue[1]
  assert(rawequal(sampled.data.n, null) and rawequal(sampled.data.l[1], null), "a sample keeps it")
  assert(rawequal(batches[1].records[2].data.n, null), "an observer's record keeps it")
  assert(rawequal(batches[2].rejection.cause.n, null), "an observer's rejection keeps it")
  assert(rawequal(raised.cause.n, null), "the host's rejection keeps it")
  local taken = s:takeResidue()[1]
  assert(rawequal(taken.data.n, null), "a take keeps it")
  assert(rawequal(Event("fx_bare", null).data, null), "as the data itself")

  -- core 12 form: still passed in events, but its writable metatable never
  -- reaches an observer's rejection (copied as its own fields)
  local old = setmetatable({}, { __readonly = true, __newindex = function() error("immutable", 2) end })
  local t = Stream()
  t:register(Gate("fx_old", function() error({ n = old }) end))
  local seen
  t:attach(function(b) seen = b.rejection end)
  local _, r2 = pcall(t.emit, t, Event("fx_old", { n = old }))
  assert(rawequal(r2.cause.n, old), "the host's rejection carries what the gate raised")
  assert(not rawequal(seen.cause.n, old), "the observer's copy does not carry the core 12 form")
  assertEqual(getmetatable(seen.cause.n), false)
  assert(SEAM.rejection_copy_problem(seen, r2) == nil, tostring(SEAM.rejection_copy_problem(seen, r2)))
end)

-- ── the rejection an observer is shown ─────────

test("an observer's rejection is plain at every depth: own fields only, no metatable, nothing shared", function()
  local Err = { __index = { kind = "io", describe = function() return "d" end }, __tostring = function() return "E" end }
  local cause = setmetatable({ msg = "m", nested = { 1, { 2 } } }, Err)
  local s = Stream()
  s:register(Gate("fx_err", function() error(cause) end))
  local copies = {}
  s:attach(function(b) copies[#copies + 1] = b.rejection end)
  s:attach(function(b) copies[#copies + 1] = b.rejection end)
  local _, raised = pcall(s.emit, s, Event("fx_err"))
  assertEqual(#copies, 2)
  for i, c in ipairs(copies) do
    assert(SEAM.rejection_copy_problem(c, raised) == nil, "copy " .. i .. ": " .. tostring(SEAM.rejection_copy_problem(c, raised)))
    assertEqual(c.cause.msg, "m", "copy " .. i .. ": the cause's own field")
    assertEqual(c.cause.kind, nil, "copy " .. i .. ": an inherited field is not carried")
    assertEqual(c.cause.nested[2][1], 2)
    assertEqual(c.message, raised.message, "copy " .. i .. ": the message carries the rendering")
    for t in pairs(reach({ c })) do
      assertEqual(getmetatable(t), false, "copy " .. i .. ": a table in it answers getmetatable")
    end
    assertEqual(overlap(reach({ c }), reach({ raised })), 0, "copy " .. i .. " shares a table with the raised rejection")
  end
  assertEqual(overlap(reach({ copies[1] }), reach({ copies[2] })), 0, "the two observers' copies share a table")
  assertEqual(tostring(cause), "E", "the gate's error object is untouched")
  assertEqual(cause.kind, "io")
end)

H.finish("stream-stateless")
