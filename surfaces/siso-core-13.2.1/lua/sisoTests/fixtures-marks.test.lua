-- fixtures-marks: the runner (code/fixtures/run.lua) copies fixture data
-- through kit.data's API (contract kit-api): a marked array is copied
-- with data.is_array / data.array, data.null is kept as itself, and every
-- other table is a plain table. It never copies a metatable, so it holds
-- whether the marks answer getmetatable with a table (the kit before
-- stateless-marks) or with a string (kit-api, core 13.0.0).
--
-- The runner is loaded fresh against each kit.data: the workspace's own,
-- and two test-local stand-ins that implement only the marks half of
-- kit-api (null, array, is_array), one per form of mark. Event is wrapped
-- so the test sees exactly the data the runner hands it, before the core
-- touches it.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local realData = require("kit.data")
local RealEvent = require("core.event")
local RealGate = require("core.gate")
-- loaded before the runner, so the stream holds the real Event and Gate
local Stream = require("core.stream")

local function joined(list) return table.concat(list, "; ") end

-- ── kit.data stand-ins: marks only ──────────────────────────────

-- marks answer getmetatable with strings (kit-api, core 13.0.0)
local function stringMarks()
  local d = {}
  local ArrayMt = { __metatable = "kit.data.array" }
  d.null = setmetatable({}, {
    __metatable = "readonly:kit.data.null",
    __newindex = function() error("data.null is immutable", 2) end,
  })
  function d.array(t) return setmetatable(t, ArrayMt) end
  function d.is_array(t) return type(t) == "table" and getmetatable(t) == "kit.data.array" end
  return d
end

-- marks answer getmetatable with a table (the kit before stateless-marks)
local function tableMarks()
  local d = {}
  local ArrayMt = {}
  d.null = setmetatable({}, { __newindex = function() error("data.null is immutable", 2) end })
  function d.array(t) return setmetatable(t, ArrayMt) end
  function d.is_array(t) return type(t) == "table" and getmetatable(t) == ArrayMt end
  return d
end

local kits = {
  { name = "the workspace's kit.data", data = realData },
  { name = "marks answering strings", data = stringMarks() },
  { name = "marks answering tables", data = tableMarks() },
}

-- ── the runner, loaded against one kit.data ─────────────────────

-- returns run, `entered`: every (type, data) the runner handed Event,
-- and `transforms`: every gate transform it built, by signature
local function runnerWith(d)
  local saved = {
    data = package.loaded["kit.data"],
    event = package.loaded["core.event"],
    gate = package.loaded["core.gate"],
    run = package.loaded["fixtures.run"],
  }
  local entered, transforms = {}, {}
  package.loaded["kit.data"] = d
  package.loaded["core.event"] = function(t, x)
    entered[#entered + 1] = { type = t, data = x }
    return RealEvent(t, x)
  end
  package.loaded["core.gate"] = function(sig, fn)
    transforms[sig] = fn
    return RealGate(sig, fn)
  end
  package.loaded["fixtures.run"] = nil
  local ok, run = pcall(require, "fixtures.run")
  package.loaded["kit.data"] = saved.data
  package.loaded["core.event"] = saved.event
  package.loaded["core.gate"] = saved.gate
  package.loaded["fixtures.run"] = saved.run
  if not ok then error("loading the runner raised " .. tostring(run), 0) end
  return run, entered, transforms
end

-- data with every kind of table the fixture JSON decodes to
local function sample(d)
  return { list = d.array({ 1, "x", d.null }), obj = { k = "v", inner = d.array({ 2 }) }, n = d.null }
end

-- the whole of a runner's copy of sample(d), against the original
local function copyProblems(d, original, got)
  local p = {}
  local function bad(s) p[#p + 1] = s end
  if type(got) ~= "table" then return { "the data is " .. type(got) } end
  if rawequal(got, original) then bad("the data is the fixture's own table, not a copy") end
  if getmetatable(got) ~= nil then bad("the data has a metatable") end
  local list = got.list
  if type(list) ~= "table" then bad("list is " .. type(list)) else
    if rawequal(list, original.list) then bad("list is the fixture's own table") end
    if not d.is_array(list) then bad("list lost its array mark") end
    if list[1] ~= 1 or list[2] ~= "x" then bad("list's values changed") end
    if not rawequal(list[3], d.null) then bad("list[3] is not data.null itself") end
    if #list ~= 3 then bad("list holds " .. #list .. " values") end
  end
  local obj = got.obj
  if type(obj) ~= "table" then bad("obj is " .. type(obj)) else
    if rawequal(obj, original.obj) then bad("obj is the fixture's own table") end
    if getmetatable(obj) ~= nil then bad("obj, an object, gained a metatable") end
    if d.is_array(obj) then bad("obj, an object, is marked as an array") end
    if obj.k ~= "v" then bad("obj.k changed") end
    if type(obj.inner) ~= "table" or not d.is_array(obj.inner) or obj.inner[1] ~= 2 then
      bad("obj.inner lost its value or its array mark")
    end
  end
  if not rawequal(got.n, d.null) then bad("n is not data.null itself") end
  local keys = 0
  for _ in pairs(got) do keys = keys + 1 end
  if keys ~= 3 then bad("the data holds " .. keys .. " fields, expected 3") end
  return p
end

-- what the runner handed Event for `type`, the last time
local function enteredAs(entered, t)
  for i = #entered, 1, -1 do if entered[i].type == t then return entered[i].data end end
  return nil
end

-- ── cases ───────────────────────────────────────────────────────

for _, kit in ipairs(kits) do
  local d = kit.data

  H.test(kit.name .. ": an entry's data is copied through the marks", function()
    local run, entered = runnerWith(d)
    local data = sample(d)
    local f = {
      format = 1, name = "an entry with marks", covers = { "Rule 4" }, gates = {},
      steps = { { emit = { type = "q", data = data }, expect = "settled" } },
    }
    local r = run(f, Stream)
    local p = copyProblems(d, data, enteredAs(entered, "q"))
    H.assert(#p == 0, joined(p))
    H.assert(r.passed == r.total, r.passed .. "/" .. r.total .. ": " .. joined(r.problems))
  end)

  H.test(kit.name .. ": a gate's emitted data is copied through the marks, every call", function()
    local run, entered = runnerWith(d)
    local data = sample(d)
    local f = {
      format = 1, name = "a gate emitting marks", covers = { "Rule 1" },
      gates = { { signature = "a", does = { emit = { { type = "out", data = data } } } } },
      steps = { { emit = { type = "a" }, expect = "settled" }, { emit = { type = "a" }, expect = "settled" } },
    }
    local r = run(f, Stream)
    local outs = {}
    for _, e in ipairs(entered) do if e.type == "out" then outs[#outs + 1] = e.data end end
    H.assertEqual(#outs, 2, "out events built")
    for i, got in ipairs(outs) do
      local p = copyProblems(d, data, got)
      H.assert(#p == 0, "call " .. i .. ": " .. joined(p))
    end
    H.assert(not rawequal(outs[1], outs[2]), "two calls share one copy")
    H.assert(r.passed == r.total, r.passed .. "/" .. r.total .. ": " .. joined(r.problems))
  end)

  H.test(kit.name .. ": a raw return is copied through the marks", function()
    local run, _, transforms = runnerWith(d)
    local raw = d.array({ 1, d.null })
    local f = {
      format = 1, name = "a raw array", covers = { "Rule 1" },
      gates = { { signature = "a", does = { raw = raw } } },
      steps = { { emit = { type = "a" }, expect = { rejected = { code = "SISO_NOT_EVENT" } } } },
    }
    -- a stream that only takes registrations: no gate runs inside it
    local function Inert()
      return { register = function() end, attach = function() end, emit = function() end }
    end
    run(f, Inert)
    -- the transform the runner built, called directly: only it runs
    H.assert(type(transforms.a) == "function", "the runner built no gate a")
    local returned = transforms.a(RealEvent("a"))
    H.assert(type(returned) == "table", "the gate returned " .. type(returned))
    H.assert(not rawequal(returned, raw), "the raw value is the fixture's own table")
    H.assert(d.is_array(returned), "the raw copy lost its array mark")
    H.assert(returned[1] == 1 and rawequal(returned[2], d.null), "the raw copy's values changed")
  end)
end

H.test("the workspace's kit.data: a decoded fixture with marks passes end to end", function()
  local run = runnerWith(realData)
  local f = assert(realData.decode([[{"format":1,"name":"decoded marks","covers":["Rule 4"],
    "gates":[{"signature":"a","does":{"emitOne":{"type":"b","data":{"l":[1,2],"z":null,"o":{"k":"v"}}}}}],
    "steps":[{"emit":{"type":"a","data":{"l":[3]}},"expect":"settled"},
             {"take":[{"type":"b","data":{"l":[1,2],"z":null,"o":{"k":"v"}}}]}]}]]))
  local r = run(f)
  H.assert(r.passed == r.total and #r.problems == 0, r.passed .. "/" .. r.total .. ": " .. joined(r.problems))
end)

H.finish("fixtures-marks")
