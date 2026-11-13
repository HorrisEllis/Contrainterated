-- contract test: fixture-format (code/contracts/fixture_format.lua).
-- The shape check accepts well-formed fixtures and names malformed ones;
-- the matching rules are order-free (K1).
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local F = require("contracts.fixture_format")

local function joined(list) return table.concat(list, "; ") end
local function has(list, needle)
  for _, s in ipairs(list) do if s:find(needle, 1, true) then return true end end
  return false
end

-- a decoded fixture, as a JSON decoder would give it
local cycle = {
  format = 1,
  name = "an indirect cycle is rejected at the first claimed delivery deeper than the gate count",
  covers = { "Rule 3", "K5" },
  gates = {
    { signature = "a", does = { emitOne = { type = "b" } } },
    { signature = "b", does = { emit = { { type = "a", data = { n = 1 } }, { raw = 42 } } } },
  },
  steps = {
    { emit = { type = "a" }, expect = { rejected = { code = "SISO_CYCLE", eventType = "a", depth = 3, at = { "a", "b", "a" } },
      trace = { { type = "a", claimed = true, ["then"] = { { type = "b", claimed = true, ["then"] = { { type = "a", claimed = true } } } } } } } },
    { sample = { residue = {}, gateCount = 2 } },
    { register = { signature = "a", does = { nothing = true } }, refused = true },
    { emitRaw = "x", expect = { rejected = { code = "SISO_NOT_EVENT" } } },
    { take = {} },
  },
}

H.test("a well-formed fixture passes", function()
  local p = F.problems(cycle)
  H.assert(#p == 0, joined(p))
end)

H.test("malformed fixtures are named", function()
  H.assert(has(F.problems({ format = 2, name = "x", covers = { "K1" }, gates = {}, steps = { { take = {} } } }), "format"), "format")
  H.assert(has(F.problems({ format = 1, name = "x", covers = {}, gates = {}, steps = { { take = {} } } }), "covers"), "covers")
  H.assert(has(F.problems({ format = 1, name = "x", covers = { "K1" }, gates = { { signature = "a", does = { emit = {}, throw = "x" } } }, steps = { { take = {} } } }), "exactly one"), "two behaviours")
  H.assert(has(F.problems({ format = 1, name = "x", covers = { "K1" }, gates = {}, steps = { { emit = { type = "a", data = { f = 1.5 } }, expect = "settled" } } }), "float"), "float data")
  H.assert(has(F.problems({ format = 1, name = "x", covers = { "K1" }, gates = {}, steps = { { emit = { type = "a" }, expect = { rejected = { code = "NOPE" } } } } }), "known code"), "unknown code")
  H.assert(has(F.problems({ format = 1, name = "x", covers = { "K1" }, gates = {}, steps = { { emit = { type = "a" }, expect = { settled = true, rejected = { code = "SISO_CYCLE" } } } } }), "exactly one of"), "both outcomes")
  H.assert(has(F.problems({ format = 1, name = "x", covers = { "K1" }, gates = {}, steps = { { wait = 1 } } }), "a step is one of"), "unknown step")
end)

H.test("residue matches as a multiset", function()
  local a = { { type = "x", data = { v = 1 } }, { type = "y" }, { type = "x", data = { v = 1 } } }
  local b = { { type = "y", data = {} }, { type = "x", data = { v = 1 } }, { type = "x", data = { v = 1 } } }
  H.assert((F.residue_match(a, b)), "same multiset in another order")
  H.assert(not F.residue_match(a, { b[1], b[2] }), "a missing copy")
end)

H.test("a trace matches whatever the delivery order", function()
  local expected = { { type = "root", claimed = true, ["then"] = {
    { type = "a", claimed = true, ["then"] = { { type = "a1", claimed = false } } },
    { type = "b", claimed = false } } } }
  local bfs = {
    { eventId = 1, eventType = "root", claimed = true },
    { eventId = 2, causeId = 1, eventType = "a", claimed = true },
    { eventId = 3, causeId = 1, eventType = "b", claimed = false },
    { eventId = 4, causeId = 2, eventType = "a1", claimed = false },
  }
  local dfs = {
    { eventId = 1, eventType = "root", claimed = true },
    { eventId = 2, causeId = 1, eventType = "a", claimed = true },
    { eventId = 3, causeId = 2, eventType = "a1", claimed = false },
    { eventId = 4, causeId = 1, eventType = "b", claimed = false },
  }
  H.assert((F.trace_match(expected, bfs)), "breadth-first")
  H.assert((F.trace_match(expected, dfs)), "depth-first")
  local wrong = { bfs[1], bfs[2], bfs[3], { eventId = 4, causeId = 3, eventType = "a1", claimed = false } }
  H.assert(not F.trace_match(expected, wrong), "a1 caused by the wrong delivery")
end)

H.test("record_at finds a delivery by its path of types, in any order", function()
  local recs = {
    { eventId = 1, eventType = "a", claimed = true },
    { eventId = 2, causeId = 1, eventType = "c", claimed = false },
    { eventId = 3, causeId = 1, eventType = "b", claimed = true },
    { eventId = 4, causeId = 3, eventType = "a", claimed = true },
  }
  local r = F.record_at(recs, { "a", "b", "a" })
  H.assert(r and r.eventId == 4, "the a caused by b")
  H.assert(F.record_at(recs, { "a", "x" }) == nil, "no such path")
end)

H.finish("contract fixture-format")
