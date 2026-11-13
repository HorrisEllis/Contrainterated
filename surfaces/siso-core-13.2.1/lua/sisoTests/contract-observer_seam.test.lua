-- contract test: observer-seam (code/contracts/observer_seam.lua).
-- Shows the contract's check passes on an implementation that honours it
-- and catches 11.1's observer (called during the propagation) and the
-- other departures the contract rules out.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local C = require("contracts.observer_seam")
local fake = require("contract-fake-core")

local function joined(list) return table.concat(list, "; ") end
local function has(list, needle)
  for _, s in ipairs(list) do if s:find(needle, 1, true) then return true end end
  return false
end

H.test("the seam holds on an implementation that honours it", function()
  local p = C.check.seam(fake.new())
  H.assert(#p == 0, joined(p))
end)

H.test("11.1: an observer called during the propagation is caught", function()
  local p = C.check.seam(fake.new({ observeDuring = true }))
  H.assert(has(p, "crossings reached the propagation"), joined(p))
  H.assert(has(p, "batches"), joined(p))
end)

H.test("11.1: an observer's raise escaping emit is caught", function()
  local p = C.check.seam(fake.new({ unprotected = true }))
  H.assert(has(p, "raising observer"), joined(p))
end)

H.test("one batch shared by every observer is caught", function()
  local p = C.check.seam(fake.new({ sharedBatch = true }))
  H.assert(has(p, "reached another observer"), joined(p))
end)

H.test("11.1 R1: ids counted across propagations break the batch", function()
  local p = C.check.seam(fake.new({ countIds = true }))
  H.assert(has(p, "eventId is"), joined(p))
end)

H.test("ownership holds on an implementation that honours it", function()
  local p = C.check.ownership(fake.new())
  H.assert(#p == 0, joined(p))
end)

H.test("12.0 X6: records that share the residue's frozen data are caught", function()
  local p = C.check.ownership(fake.new({ shareFrozen = true }))
  H.assert(has(p, "reached the residue"), joined(p))
  H.assert(has(p, "another observer"), joined(p))
end)

H.test("12.0 C-2: observers shown the very rejection raised are caught", function()
  local f = fake.new({ sharedRejection = true })
  local p = C.check.seam(f)
  H.assert(has(p, "very table raised"), joined(p))
  p = C.check.ownership(f)
  H.assert(has(p, "reached the rejection emit raised"), joined(p))
end)

H.test("rejection_copy_problem: a copy, the same table, a changed field", function()
  local raised = { code = "SISO_GATE_THREW", message = "m", eventId = 1, cause = { "x", n = { 1 } } }
  local function ro(t)
    return setmetatable({}, { __index = t, __newindex = function() error("read-only", 2) end,
      __pairs = function() return next, t, nil end, __metatable = false })
  end
  local good = ro({ code = "SISO_GATE_THREW", message = "m", eventId = 1, cause = ro({ "x", n = ro({ 1 }) }) })
  H.assertEqual(C.rejection_copy_problem(good, raised), nil)
  H.assert(C.rejection_copy_problem(raised, raised):find("very table", 1, true), "the same table")
  local off = ro({ code = "SISO_GATE_THREW", message = "m", eventId = 2, cause = ro({ "x", n = ro({ 1 }) }) })
  H.assert(C.rejection_copy_problem(off, raised):find("eventId", 1, true), "an unequal field")
  local deep = ro({ code = "SISO_GATE_THREW", message = "m", eventId = 1, cause = ro({ "x", n = ro({ 2 }) }) })
  H.assert(C.rejection_copy_problem(deep, raised):find("cause", 1, true), "an unequal cause")
  local extra = ro({ code = "SISO_GATE_THREW", message = "m", eventId = 1, cause = ro({ "x", n = ro({ 1 }) }), depth = 3 })
  H.assert(C.rejection_copy_problem(extra, raised):find("lacks", 1, true), "an added field")
  local writable = { code = "SISO_GATE_THREW", message = "m", eventId = 1, cause = { "x", n = { 1 } } }
  H.assert(C.rejection_copy_problem(writable, raised) ~= nil, "a writable copy")
  local withMeta = setmetatable({}, { __index = good, __newindex = function() error("read-only", 2) end,
    __pairs = function() return pairs(good) end })
  H.assert(C.rejection_copy_problem(withMeta, raised):find("getmetatable", 1, true), "a copy that hands out its metatable")
  local sharedCause = ro({ code = "SISO_GATE_THREW", message = "m", eventId = 1, cause = raised.cause })
  H.assert(C.rejection_copy_problem(sharedCause, raised):find("raised table itself", 1, true), "a copy sharing the raised cause")
end)

H.test("13.0.0-rc1: a rejection copy that answers the host's metatable is caught", function()
  local f = fake.new({ richRejection = true })
  local p = C.check.seam(f)
  H.assert(has(p, "getmetatable"), joined(p))
  p = C.check.ownership(f)
  H.assert(has(p, "through its copy's metatable"), joined(p))
end)

H.test("batch_problems: a consistent batch passes", function()
  local b = { outcome = "settled", records = {
    { eventId = 1, eventType = "a", claimed = true, data = {} },
    { eventId = 2, causeId = 1, eventType = "b", claimed = false, data = {} },
  } }
  H.assertEqual(#C.batch_problems(b), 0)
end)

H.test("batch_problems: inconsistencies are named", function()
  local function rec(id, cause, claimed) return { eventId = id, causeId = cause, eventType = "t", claimed = claimed, data = {} } end
  H.assert(has(C.batch_problems({ outcome = "settled", records = { rec(1, nil, true), rec(3, 1, false) } }), "eventId is 3"), "gap in ids")
  H.assert(has(C.batch_problems({ outcome = "settled", records = { rec(1, nil, false), rec(2, 1, false) } }), "not claimed"), "cause unclaimed")
  H.assert(has(C.batch_problems({ outcome = "settled", records = { rec(1, 1, true) } }), "has a cause"), "entry with cause")
  H.assert(has(C.batch_problems({ outcome = "rejected", records = {} }), "carries no rejection"), "rejected without rejection")
  H.assert(has(C.batch_problems({ outcome = "rejected", rejection = { eventId = 2 }, records = { rec(1, nil, true) } }), "do not end"), "records past the rejection")
  H.assert(has(C.batch_problems({ outcome = "settled", records = {}, extra = 1 }), "unexpected field"), "extra field")
end)

H.finish("contract observer-seam")
