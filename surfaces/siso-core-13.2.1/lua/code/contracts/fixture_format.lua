--[[
contract: fixture-format — the language-agnostic suite every instance
must pass (core 12).

Between: fixtures -> stream, observer (and every future instance: a JS
port is checked against the same files). Only a contracts phase changes
this file. The fixture files themselves (fixtures/*.json) are the
fixtures boundary's; this is the format they are written in, and the
matching rules every runner applies.

A FIXTURE is one JSON file:
  {
    "format": 1,
    "name":   "a sentence",
    "covers": ["Rule 3", "K5", …],      principle items it pins
    "gates":  [ gate, … ],              registered before the first step
    "steps":  [ step, … ]               run in order
  }
A GATE is { "signature": "a", "does": behaviour }. Behaviours are
test-only (dev notes §2):
  { "emit": [ out, … ] }   returns a list of these (empty: consumed)
  { "emitOne": out }       returns one Event
  { "forward": "t" }       returns Event("t", <the received event's data>)
  { "nothing": true }      returns nil
  { "throw": "message" }   raises the string "message"
  { "raw": value }         returns `value` itself, which is not an Event
  where `out` is an EVENT, or { "raw": value } for a list element that
  is not an Event.
An EVENT is { "type": "t", "data": { … } }; "data" may be omitted ({}).
Event data is plain JSON with integers only (no floats), and no empty
arrays inside it (Lua cannot tell [] from {}). The restriction is on event
data only: a list the format itself expects (gates, steps, emit, take,
sample's residue, trace, then, at) may be empty, so "take": [] pins that
the stream holds no residue.

A STEP is one of:
  { "register": gate, "refused": true|false }
  { "emit": EVENT, "expect": expectation }
  { "emitRaw": value, "expect": expectation }   the entry is not an Event
  { "take": [ EVENT, … ] }        the residue, compared as a multiset
  { "sample": { "residue": [ EVENT, … ], "gateCount": n } }
                                  the residue compared as a multiset
An EXPECTATION is:
  "settled"
  { "settled": true, "trace": [ node, … ] }
  { "rejected": { "code": "SISO_…", "eventType"?: "t", "depth"?: n,
                  "cause"?: "message", "at"?: [ "t1", "t2", … ] },
    "trace"?: [ node, … ] }
A NODE is one delivery of the propagation, as the observer's batch shows
it: { "type": "t", "claimed": true|false, "then"?: [ node, … ] }, where
"then" lists the deliveries its gate's return caused.

MATCHING (order-free: K1 is not semantics)
  - Residue is a multiset: two lists match when, rendered canonically,
    they hold the same events the same number of times.
  - A trace is a forest built from the batch's records by causeId. Two
    forests match when their roots match as a multiset and each node's
    "then" matches as a multiset, recursively.
  - "at" names the delivery a rejection names, by the path of types
    from the entry delivery (["a", "b"]: the b caused by the entry a).
    The rejection's eventId must be that record's id, and its causeId
    that record's cause. The fixture's author keeps the path unambiguous.
  - "eventType", "depth" and "cause" (the raised string) compare exactly
    when present. An expectation never names a raw id: ids follow
    delivery order, which an instance may choose.
  - A runner always attaches one observer, and fails a step whose batch
    breaks observer-seam (batch_problems).

problems(fixture) checks a decoded fixture's shape. canon(v),
residue_match(expected, actual), trace_match(expected, records) and
record_at(records, path) are the matching rules, for Lua runners.
]]

local M = {}

local BEHAVIOURS = { emit = true, emitOne = true, forward = true, nothing = true, throw = true, raw = true }
local CODES = { SISO_SELF_LOOP = true, SISO_CYCLE = true, SISO_GATE_THREW = true, SISO_NOT_EVENT = true }

local function isList(t)
  if type(t) ~= "table" then return false end
  local n = 0
  for _ in pairs(t) do n = n + 1 end
  return n == #t
end

local function sortedKeys(t)
  local ks = {}
  for k in pairs(t) do ks[#ks + 1] = k end
  table.sort(ks, function(a, b) return tostring(a) < tostring(b) end)
  return ks
end

-- A canonical rendering of plain data: keys sorted, integers only.
function M.canon(v)
  local t = type(v)
  if t == "string" then return string.format("%q", v) end
  if t == "number" then
    if math.type(v) ~= "integer" then error("fixture data holds a float: " .. tostring(v), 0) end
    return tostring(v)
  end
  if t == "boolean" then return tostring(v) end
  if t == "table" then
    local parts = {}
    for _, k in ipairs(sortedKeys(v)) do parts[#parts + 1] = M.canon(k) .. ":" .. M.canon(v[k]) end
    return "{" .. table.concat(parts, ",") .. "}"
  end
  error("not plain data: " .. t, 0)
end

local function renderEvent(e) return M.canon({ type = e.type, data = e.data or {} }) end

local function multiset(list, render)
  local r = {}
  for i, x in ipairs(list) do r[i] = render(x) end
  table.sort(r)
  return table.concat(r, "|")
end

function M.residue_match(expected, actual)
  local a, b = multiset(expected, renderEvent), multiset(actual, renderEvent)
  if a == b then return true end
  return false, "residue: expected " .. a .. ", got " .. b
end

local function renderNode(n)
  return "(" .. M.canon(n.type) .. (n.claimed and "+" or "-") .. "[" .. multiset(n["then"] or {}, renderNode) .. "])"
end

-- the forest of a batch's records, as nodes
function M.forest(records)
  local nodes, roots = {}, {}
  for i, r in ipairs(records) do
    nodes[i] = { type = r.eventType, claimed = r.claimed, ["then"] = {} }
    if r.causeId == nil then roots[#roots + 1] = nodes[i]
    else local parent = nodes[r.causeId]; parent["then"][#parent["then"] + 1] = nodes[i] end
  end
  return roots
end

function M.trace_match(expected, records)
  local a = multiset(expected, renderNode)
  local b = multiset(M.forest(records), renderNode)
  if a == b then return true end
  return false, "trace: expected " .. a .. ", got " .. b
end

-- the record reached by a path of types from the entry; nil, why when the
-- path matches none or more than one
function M.record_at(records, path)
  local frontier = {}
  for i, r in ipairs(records) do
    if r.causeId == nil and r.eventType == path[1] then frontier[#frontier + 1] = i end
  end
  for depth = 2, #path do
    local next_ = {}
    for i, r in ipairs(records) do
      for _, f in ipairs(frontier) do
        if r.causeId == f and r.eventType == path[depth] then next_[#next_ + 1] = i end
      end
    end
    frontier = next_
  end
  if #frontier ~= 1 then return nil, #frontier .. " deliveries match the path" end
  return records[frontier[1]]
end

-- ── shape ───────────────────────────────────────────────────────

local function eventProblems(e, where, p)
  if type(e) ~= "table" or type(e.type) ~= "string" then p[#p + 1] = where .. ": an event needs a string type"; return end
  for k in pairs(e) do if k ~= "type" and k ~= "data" then p[#p + 1] = where .. ": unexpected field " .. tostring(k) end end
  if e.data ~= nil and type(e.data) ~= "table" then p[#p + 1] = where .. ": data is not an object" end
  if e.data ~= nil then
    local ok, err = pcall(M.canon, e.data)
    if not ok then p[#p + 1] = where .. ": " .. tostring(err) end
  end
end

local function outProblems(o, where, p)
  if type(o) == "table" and o.raw ~= nil and o.type == nil then return end
  eventProblems(o, where, p)
end

local function gateProblems(g, where, p)
  if type(g) ~= "table" or type(g.signature) ~= "string" then p[#p + 1] = where .. ": a gate needs a string signature"; return end
  local d = g.does
  if type(d) ~= "table" then p[#p + 1] = where .. ": no behaviour"; return end
  local keys = sortedKeys(d)
  if #keys ~= 1 or not BEHAVIOURS[keys[1]] then p[#p + 1] = where .. ": a behaviour is exactly one of emit, emitOne, forward, nothing, throw, raw"; return end
  if d.emit ~= nil then
    if not isList(d.emit) then p[#p + 1] = where .. ": emit takes a list" else
      for i, o in ipairs(d.emit) do outProblems(o, where .. ".emit[" .. i .. "]", p) end
    end
  end
  if d.emitOne ~= nil then eventProblems(d.emitOne, where .. ".emitOne", p) end
  if d.forward ~= nil and type(d.forward) ~= "string" then p[#p + 1] = where .. ": forward names a type" end
  if d.throw ~= nil and type(d.throw) ~= "string" then p[#p + 1] = where .. ": throw takes a string" end
end

local function nodeProblems(n, where, p)
  if type(n) ~= "table" or type(n.type) ~= "string" or type(n.claimed) ~= "boolean" then
    p[#p + 1] = where .. ": a node is { type, claimed, then? }"; return
  end
  if n["then"] ~= nil then
    if not isList(n["then"]) then p[#p + 1] = where .. ": then is a list" else
      for i, c in ipairs(n["then"]) do nodeProblems(c, where .. ".then[" .. i .. "]", p) end
    end
  end
end

local function expectProblems(x, where, p)
  if x == "settled" then return end
  if type(x) ~= "table" then p[#p + 1] = where .. ": expect is \"settled\" or an object"; return end
  if (x.settled ~= nil) == (x.rejected ~= nil) then p[#p + 1] = where .. ": expect exactly one of settled, rejected" end
  if x.rejected ~= nil then
    local r = x.rejected
    if type(r) ~= "table" or not CODES[r.code] then p[#p + 1] = where .. ": rejected needs a known code" else
      if r.at ~= nil and not isList(r.at) then p[#p + 1] = where .. ": at is a list of types" end
      if r.depth ~= nil and math.type(r.depth) ~= "integer" then p[#p + 1] = where .. ": depth is an integer" end
    end
  end
  if x.trace ~= nil then
    if not isList(x.trace) then p[#p + 1] = where .. ": trace is a list" else
      for i, n in ipairs(x.trace) do nodeProblems(n, where .. ".trace[" .. i .. "]", p) end
    end
  end
end

function M.problems(f)
  local p = {}
  if type(f) ~= "table" then return { "a fixture is an object" } end
  if f.format ~= 1 then p[#p + 1] = "format is not 1" end
  if type(f.name) ~= "string" then p[#p + 1] = "no name" end
  if not isList(f.covers) or #f.covers == 0 then p[#p + 1] = "covers lists the items it pins" end
  if not isList(f.gates) then p[#p + 1] = "gates is a list" else
    for i, g in ipairs(f.gates) do gateProblems(g, "gates[" .. i .. "]", p) end
  end
  if not isList(f.steps) or #f.steps == 0 then p[#p + 1] = "steps is a non-empty list"; return p end
  for i, s in ipairs(f.steps) do
    local where = "steps[" .. i .. "]"
    if type(s) ~= "table" then p[#p + 1] = where .. ": not an object"
    elseif s.register ~= nil then
      gateProblems(s.register, where .. ".register", p)
      if type(s.refused) ~= "boolean" then p[#p + 1] = where .. ": register says whether it is refused" end
    elseif s.emit ~= nil then
      eventProblems(s.emit, where .. ".emit", p)
      expectProblems(s.expect, where, p)
    elseif s.emitRaw ~= nil then
      expectProblems(s.expect, where, p)
    elseif s.take ~= nil then
      if not isList(s.take) then p[#p + 1] = where .. ": take is a list" else
        for j, e in ipairs(s.take) do eventProblems(e, where .. ".take[" .. j .. "]", p) end
      end
    elseif s.sample ~= nil then
      if type(s.sample) ~= "table" or not isList(s.sample.residue) or math.type(s.sample.gateCount) ~= "integer" then
        p[#p + 1] = where .. ": sample is { residue = [...], gateCount = n }"
      end
    else
      p[#p + 1] = where .. ": a step is one of register, emit, emitRaw, take, sample"
    end
  end
  return p
end

return M
