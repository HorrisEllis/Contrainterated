--[[
fixtures runner — the Lua runner of the language-agnostic suite
(contract fixture-format, code/contracts/fixture_format.lua).

  lua5.4 code/fixtures/run.lua [dir]     from lua/ (the Lua instance)
    runs every <dir>/*.json (default ../fixtures/, the suite every
    instance shares), sorted by name, and
    prints one line per fixture, then the final line
      "fixtures: F files, P/T steps passed"
    exit 0 only when every step of every file passes.

  local run = require("fixtures.run")
  run(fixture [, Stream]) → { name, passed, total, problems = { … } }
    one decoded fixture (or one written inline as Lua tables), run on a
    fresh stream with one observer attached. `Stream` is the constructor
    (default core.stream); a test passes another to run the same fixture
    against a stream it controls.

Matching never happens here: shape is fixture_format.problems, residue
is residue_match, a trace is trace_match, "at" is record_at, every batch
is observer_seam.batch_problems, every rejection core_api's
rejection_problems, and a batch's rejection is judged against the one
raised by observer_seam.rejection_copy_problem (the observer's own
read-only copy, core 13), never by identity.
]]

local modname = ...
local asModule = type(modname) == "string" and modname:match("fixtures%.run$") ~= nil
local root = ""
if not asModule then
  root = (arg and arg[0] or ""):match("^(.-)code/fixtures/run%.lua$") or ""
  package.path = root .. "code/?.lua;" .. package.path
end

local Event = require("core.event")
local Gate = require("core.gate")
local Stream = require("core.stream")
local F = require("contracts.fixture_format")
local seam = require("contracts.observer_seam")
local api = require("contracts.core_api")
local data = require("kit.data")

-- ── gates built from test-only behaviours ───────────────────────

-- fixture data copied through kit.data's API (kit-api): a marked array
-- stays marked (data.array), data.null is kept as itself, every other
-- table is plain. No metatable is copied, so the copy holds whatever
-- getmetatable answers for a mark.
local function copy(v)
  if type(v) ~= "table" or rawequal(v, data.null) then return v end
  local t = {}
  for k, x in pairs(v) do t[k] = copy(x) end
  if data.is_array(v) then return data.array(t) end
  return t
end

local function outOf(o)
  if o.type == nil and o.raw ~= nil then return copy(o.raw) end
  return Event(o.type, copy(o.data))
end

local function transformOf(does)
  if does.emit ~= nil then
    return function()
      local list = {}
      for i, o in ipairs(does.emit) do list[i] = outOf(o) end
      return list
    end
  elseif does.emitOne ~= nil then
    return function() return outOf(does.emitOne) end
  elseif does.forward ~= nil then
    return function(e) return Event(does.forward, e.data) end
  elseif does.nothing ~= nil then
    return function() return nil end
  elseif does.throw ~= nil then
    return function() error(does.throw, 0) end
  end
  return function() return copy(does.raw) end
end

local function gateOf(g) return Gate(g.signature, transformOf(g.does)) end

-- ── steps ───────────────────────────────────────────────────────

local function addAll(into, label, list)
  for _, s in ipairs(list) do into[#into + 1] = label .. ": " .. s end
end

local function check(into, label, ok, why)
  if not ok then into[#into + 1] = label .. ": " .. tostring(why) end
end

local function show(err)
  if type(err) == "table" and err.code ~= nil then return tostring(err.code) .. " (" .. tostring(err.message) .. ")" end
  return tostring(err)
end

-- an entry through the door, against its expectation
local function entry(s, batches, value, expect, raw, p)
  local before = s:sampleHere()
  for i = #batches, 1, -1 do batches[i] = nil end
  local ok, err = pcall(s.emit, s, value)
  if #batches ~= 1 then
    p[#p + 1] = "the observer was shown " .. #batches .. " batches, expected 1"
    return
  end
  local b = batches[1]
  addAll(p, "batch", seam.batch_problems(b))
  local x = expect
  if x == "settled" or (type(x) == "table" and x.settled ~= nil) then
    if not ok then p[#p + 1] = "expected settled, got rejected " .. show(err); return end
    check(p, "batch", b.outcome == "settled", "outcome " .. tostring(b.outcome))
  else
    if ok then p[#p + 1] = "expected rejected " .. tostring(x.rejected.code) .. ", it settled"; return end
    addAll(p, "rejection", api.rejection_problems(err, raw and { noDelivery = true } or nil))
    if type(err) ~= "table" then return end
    check(p, "batch", b.outcome == "rejected", "outcome " .. tostring(b.outcome))
    local why = seam.rejection_copy_problem(b.rejection, err)
    check(p, "batch", why == nil, why)
    local want = x.rejected
    for _, k in ipairs({ "code", "eventType", "depth", "cause" }) do
      if want[k] ~= nil and err[k] ~= want[k] then
        p[#p + 1] = k .. ": expected " .. tostring(want[k]) .. ", got " .. tostring(err[k])
      end
    end
    if want.at ~= nil then
      local rec, why = F.record_at(b.records, want.at)
      if not rec then p[#p + 1] = "at " .. table.concat(want.at, ">") .. ": " .. why
      elseif err.eventId ~= rec.eventId or err.causeId ~= rec.causeId then
        p[#p + 1] = "at " .. table.concat(want.at, ">") .. ": the rejection names delivery "
          .. tostring(err.eventId) .. "<" .. tostring(err.causeId) .. ", the path reaches "
          .. tostring(rec.eventId) .. "<" .. tostring(rec.causeId)
      end
    end
    -- a rejected propagation commits nothing (K5)
    local after = s:sampleHere()
    check(p, "rejection committed", F.residue_match(before.residue, after.residue))
  end
  if type(x) == "table" and x.trace ~= nil then check(p, "trace", F.trace_match(x.trace, b.records)) end
end

local function step(s, batches, st, p)
  if st.register ~= nil then
    local ok, err = pcall(s.register, s, gateOf(st.register))
    if st.refused and ok then p[#p + 1] = "register " .. st.register.signature .. ": expected refused, it was accepted"
    elseif not st.refused and not ok then p[#p + 1] = "register " .. st.register.signature .. ": refused (" .. tostring(err) .. ")" end
  elseif st.emit ~= nil then
    entry(s, batches, Event(st.emit.type, copy(st.emit.data)), st.expect, false, p)
  elseif st.emitRaw ~= nil then
    entry(s, batches, copy(st.emitRaw), st.expect, true, p)
  elseif st.take ~= nil then
    check(p, "take", F.residue_match(st.take, s:takeResidue()))
  elseif st.sample ~= nil then
    local smp = s:sampleHere()
    check(p, "sample", F.residue_match(st.sample.residue, smp.residue))
    check(p, "sample", smp.gateCount == st.sample.gateCount,
      "gateCount: expected " .. tostring(st.sample.gateCount) .. ", got " .. tostring(smp.gateCount))
  end
end

-- ── one fixture ─────────────────────────────────────────────────

local function run(fixture, StreamOf)
  local result = { name = type(fixture) == "table" and fixture.name or "?", passed = 0, total = 1, problems = {} }
  local shape = F.problems(fixture)
  if type(fixture) == "table" and type(fixture.steps) == "table" and #fixture.steps > 0 then
    result.total = #fixture.steps
  end
  if #shape > 0 then
    addAll(result.problems, "shape", shape)
    return result
  end
  local s = (StreamOf or Stream)()
  local batches = {}
  s:attach(function(b) batches[#batches + 1] = b end)
  for i, g in ipairs(fixture.gates) do
    local ok, err = pcall(s.register, s, gateOf(g))
    if not ok then result.problems[#result.problems + 1] = "gates[" .. i .. "]: refused (" .. tostring(err) .. ")" end
  end
  if #result.problems > 0 then return result end
  for i, st in ipairs(fixture.steps) do
    local p = {}
    local ok, err = pcall(step, s, batches, st, p)
    if not ok then p[#p + 1] = "raised " .. show(err) end
    if #p == 0 then result.passed = result.passed + 1 end
    addAll(result.problems, "steps[" .. i .. "]", p)
  end
  return result
end

-- ── command line ────────────────────────────────────────────────

if asModule then return run end

local dir = (arg and arg[1]) or (root .. "../fixtures")
local files = {}
local ls = io.popen('ls -1 "' .. dir .. '" 2>/dev/null')
if ls then
  for name in ls:lines() do if name:match("%.json$") then files[#files + 1] = name end end
  ls:close()
end
table.sort(files)

local passed, total, clean = 0, 0, #files > 0
for _, name in ipairs(files) do
  local fh = io.open(dir .. "/" .. name, "r")
  local text = fh and fh:read("a") or ""
  if fh then fh:close() end
  local fixture, why = data.decode(text)
  local r
  if fixture == nil then
    r = { name = "?", passed = 0, total = 1, problems = { "decode: " .. tostring(why) } }
  else
    r = run(fixture)
  end
  passed, total = passed + r.passed, total + r.total
  local good = r.passed == r.total and #r.problems == 0
  clean = clean and good
  print((good and "ok    " or "FAIL  ") .. name .. "  " .. r.passed .. "/" .. r.total .. "  " .. tostring(r.name))
  for _, s in ipairs(r.problems) do print("        " .. s) end
end
print("fixtures: " .. #files .. " files, " .. passed .. "/" .. total .. " steps passed")
os.exit(clean and 0 or 1)
