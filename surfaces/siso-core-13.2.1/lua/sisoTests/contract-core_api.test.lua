-- contract test: core-api (code/contracts/core_api.lua).
-- Shows the contract's checks pass on an implementation that honours it
-- and catch each departure 11.1 had. The core itself is checked against
-- the contract by the stream phases' tests.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local C = require("contracts.core_api")
local fake = require("contract-fake-core")

local function joined(list) return table.concat(list, "; ") end
local function has(list, needle)
  for _, s in ipairs(list) do if s:find(needle, 1, true) then return true end end
  return false
end

H.test("doors hold on an implementation that honours the contract", function()
  local p = C.check.doors(fake.new())
  H.assert(#p == 0, joined(p))
end)

H.test("read-only holds on an implementation that honours the contract", function()
  local p = C.check.readonly(fake.new())
  H.assert(#p == 0, joined(p))
end)

H.test("11.1 X1: skipping non-Event outputs is caught", function()
  local p = C.check.doors(fake.new({ skipNonEvents = true }))
  H.assert(has(p, "output not an Event"), joined(p))
end)

H.test("11.1: an entry door that takes any value is caught", function()
  local p = C.check.doors(fake.new({ acceptAnyEntry = true }))
  H.assert(has(p, "entry not an Event"), joined(p))
end)

H.test("11.1 R1: ids counted across propagations are caught", function()
  local p = C.check.doors(fake.new({ countIds = true }))
  H.assert(has(p, "gate threw, again"), joined(p))
end)

H.test("11.1 R1: an event counter in the sample is caught", function()
  local p = C.check.doors(fake.new({ sampleCount = true }))
  H.assert(has(p, "sampleHere carries"), joined(p))
end)

H.test("11.1 R3: writable events are caught", function()
  local p = C.check.readonly(fake.new({ noFreeze = true }))
  H.assert(has(p, "was accepted"), joined(p))
end)

H.test("12.0: an event writable until it enters the stream is caught", function()
  local p = C.check.readonly(fake.new({ writableAtBirth = true }))
  H.assert(has(p, "a new event"), joined(p))
  H.assert(has(p, "table passed to Event reached the event"), joined(p))
end)

H.test("12.0.1 and 13.0.0-rc1: events that hand out a writable metatable are caught", function()
  local p = C.check.readonly(fake.new({ tableMeta = true }))
  H.assert(has(p, "not \"siso.event\""), joined(p))
  H.assert(has(p, "carried a count"), joined(p))
end)

H.test("13.0.0-rc1: an entry that keeps the entering code's own event is caught", function()
  local p = C.check.ownership(fake.new({ entryAlias = true }))
  H.assert(has(p, "entering code's own event"), joined(p))
end)

H.test("ownership holds on an implementation that honours the contract", function()
  local p = C.check.ownership(fake.new())
  H.assert(#p == 0, joined(p))
end)

H.test("12.0 X6: samples that share the stream's frozen objects are caught", function()
  local p = C.check.ownership(fake.new({ shareFrozen = true }))
  H.assert(has(p, "reached the stream"), joined(p))
end)

H.test("rejection_problems: a well-formed rejection passes", function()
  local r = setmetatable({ code = "SISO_CYCLE", message = "m", eventId = 3, causeId = 2, eventType = "a", depth = 3 },
    { __tostring = function(t) return t.message end })
  H.assertEqual(#C.rejection_problems(r, { code = "SISO_CYCLE", depth = 3 }), 0)
end)

H.test("rejection_problems: shape errors are named", function()
  local plain = { code = "SISO_CYCLE", message = "m", eventId = 1, depth = 1 }
  H.assert(has(C.rejection_problems(plain), "tostring"), "a rejection without __tostring")
  local mt = { __tostring = function(t) return t.message end }
  H.assert(has(C.rejection_problems(setmetatable({ code = "X", message = "m" }, mt)), "unknown code"), "unknown code")
  H.assert(has(C.rejection_problems(setmetatable({ code = "SISO_SELF_LOOP", message = "m", extra = 1 }, mt)), "unexpected field"), "extra field")
  H.assert(has(C.rejection_problems(setmetatable({ code = "SISO_SELF_LOOP", message = "m", depth = 2 }, mt)), "only by SISO_CYCLE"), "depth off a cycle")
  H.assert(has(C.rejection_problems(setmetatable({ code = "SISO_NOT_EVENT", message = "m", eventId = 1 }, mt), { noDelivery = true }), "names no delivery"), "refused entry with an id")
  H.assert(has(C.rejection_problems("boom"), "is a table"), "a string")
end)

H.finish("contract core-api")
