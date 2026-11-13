-- fixtures: the language-agnostic suite (fixtures/*.json) passes through
-- the Lua runner (code/fixtures/run.lua), every file is a well-formed,
-- portable fixture, and the runner fails a wrong expectation.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local F = require("contracts.fixture_format")
local data = require("kit.data")
local run = require("fixtures.run")

local function joined(list) return table.concat(list, "; ") end
local function has(list, needle)
  for _, s in ipairs(list) do if s:find(needle, 1, true) then return true end end
  return false
end

-- ── the suite on disk ───────────────────────────────────────────

local dir = root .. "../fixtures"
local files = {}
do
  local ls = io.popen('ls -1 "' .. dir .. '" 2>/dev/null')
  if ls then
    for name in ls:lines() do if name:match("%.json$") then files[#files + 1] = name end end
    ls:close()
  end
  table.sort(files)
end

local decoded = {}
local function load(name)
  if decoded[name] == nil then
    local fh = assert(io.open(dir .. "/" .. name, "r"))
    local text = fh:read("a")
    fh:close()
    local v, why = data.decode(text)
    decoded[name] = v or { decodeError = tostring(why) }
  end
  return decoded[name]
end

H.test("the suite exists", function()
  H.assert(#files >= 15, "fixtures/ holds " .. #files .. " files")
end)

H.test("every file decodes and is a well-formed fixture", function()
  local bad = {}
  for _, name in ipairs(files) do
    local f = load(name)
    if f.decodeError then bad[#bad + 1] = name .. ": " .. f.decodeError
    else for _, s in ipairs(F.problems(f)) do bad[#bad + 1] = name .. ": " .. s end end
  end
  H.assert(#bad == 0, joined(bad))
end)

-- nothing in a file may depend on Lua: integers only, no null, no empty arrays
H.test("every file is portable", function()
  local bad = {}
  local function walk(v, where)
    if v == data.null then bad[#bad + 1] = where .. ": null"
    elseif type(v) == "number" and math.type(v) ~= "integer" then bad[#bad + 1] = where .. ": float"
    elseif type(v) == "table" then
      if next(v) == nil and data.is_array(v) then bad[#bad + 1] = where .. ": empty array" end
      for k, x in pairs(v) do walk(x, where .. "." .. tostring(k)) end
    end
  end
  for _, name in ipairs(files) do walk(load(name), name) end
  H.assert(#bad == 0, joined(bad))
end)

H.test("the suite covers each rule and concession the core pins", function()
  local covered = {}
  for _, name in ipairs(files) do
    for _, c in ipairs(load(name).covers or {}) do covered[c] = true end
  end
  local missing = {}
  for _, item in ipairs({ "Rule 1", "Rule 3", "Rule 4", "K2", "K3", "K4", "K5", "K6", "The outside" }) do
    if not covered[item] then missing[#missing + 1] = item end
  end
  H.assert(#missing == 0, "not covered: " .. joined(missing))
end)

H.test("every fixture passes through the runner", function()
  local bad = {}
  for _, name in ipairs(files) do
    local r = run(load(name))
    if r.passed ~= r.total or #r.problems > 0 then
      bad[#bad + 1] = name .. " " .. r.passed .. "/" .. r.total .. ": " .. joined(r.problems)
    end
  end
  H.assert(#bad == 0, joined(bad))
end)

H.test("the command line runs the suite and reports it", function()
  local cmd = 'cd "' .. (root == "" and "." or root) .. '" && lua5.4 code/fixtures/run.lua 2>&1'
  local p = assert(io.popen(cmd))
  local last
  for line in p:lines() do last = line end
  local ok = p:close()
  H.assert(ok, "the runner exited non-zero: " .. tostring(last))
  local nf, ps, ts = tostring(last):match("^fixtures: (%d+) files, (%d+)/(%d+) steps passed$")
  H.assert(nf ~= nil, "final line: " .. tostring(last))
  H.assertEqual(tonumber(nf), #files, "files")
  H.assertEqual(ps, ts, "steps passed")
end)

-- ── the runner fails a wrong expectation ────────────────────────

-- a passing fixture, and copies of it with one expectation wrong
local function base()
  return {
    format = 1,
    name = "inline",
    covers = { "K1" },
    gates = {
      { signature = "a", does = { emitOne = { type = "b", data = { v = 1 } } } },
      { signature = "b", does = { emit = { { type = "c" }, { type = "d" } } } },
      { signature = "d", does = { throw = "d failed" } },
      { signature = "loop", does = { emitOne = { type = "loop" } } },
      { signature = "x", does = { forward = "y" } },
    },
    steps = {
      { emit = { type = "x", data = { n = 2 } }, expect = { settled = true,
        trace = { { type = "x", claimed = true, ["then"] = { { type = "y", claimed = false } } } } } },
      { emit = { type = "a" }, expect = { rejected = { code = "SISO_GATE_THREW", eventType = "d", cause = "d failed",
        at = { "a", "b", "d" } } } },
      { emit = { type = "loop" }, expect = { rejected = { code = "SISO_SELF_LOOP", eventType = "loop", at = { "loop" } } } },
      { register = { signature = "x", does = { nothing = true } }, refused = true },
      { sample = { residue = { { type = "y", data = { n = 2 } } }, gateCount = 5 } },
      { take = { { type = "y", data = { n = 2 } } } },
    },
  }
end

local function failsAt(label, mutate, stepIndex, needle)
  H.test("the runner fails " .. label, function()
    local f = base()
    mutate(f)
    local r = run(f)
    H.assert(r.passed == r.total - 1, label .. ": " .. r.passed .. "/" .. r.total .. " passed, expected one failure: " .. joined(r.problems))
    H.assert(has(r.problems, "steps[" .. stepIndex .. "]"), label .. ": the failure is not at step " .. stepIndex .. ": " .. joined(r.problems))
    H.assert(has(r.problems, needle), label .. ": no '" .. needle .. "' in " .. joined(r.problems))
  end)
end

H.test("the inline fixture passes as written", function()
  local r = run(base())
  H.assert(r.passed == r.total and #r.problems == 0, r.passed .. "/" .. r.total .. ": " .. joined(r.problems))
end)

failsAt("a wrong residue in a take", function(f) f.steps[6].take[1].data.n = 3 end, 6, "residue")
failsAt("an extra copy in a sample", function(f)
  f.steps[5].sample.residue[2] = { type = "y", data = { n = 2 } }
end, 5, "residue")
failsAt("a wrong gate count", function(f) f.steps[5].sample.gateCount = 4 end, 5, "gateCount")
failsAt("a wrong trace", function(f) f.steps[1].expect.trace[1]["then"][1].claimed = true end, 1, "trace")
failsAt("a wrong code", function(f) f.steps[3].expect.rejected.code = "SISO_CYCLE" end, 3, "code")
failsAt("a wrong \"at\"", function(f) f.steps[2].expect.rejected.at = { "a", "b" } end, 2, "at a>b")
failsAt("an \"at\" that reaches no delivery", function(f) f.steps[2].expect.rejected.at = { "a", "c", "d" } end, 2, "0 deliveries")
failsAt("a wrong cause", function(f) f.steps[2].expect.rejected.cause = "other" end, 2, "cause")
failsAt("a wrong event type", function(f) f.steps[3].expect.rejected.eventType = "a" end, 3, "eventType")
failsAt("a settled expectation on a rejection", function(f) f.steps[2].expect = "settled" end, 2, "expected settled")
failsAt("a rejection expected of a settled entry", function(f)
  f.steps[1].expect = { rejected = { code = "SISO_NOT_EVENT" } }
end, 1, "it settled")
failsAt("a refused registration expected to succeed", function(f) f.steps[4].refused = false end, 4, "refused")
failsAt("an accepted registration expected to be refused", function(f)
  f.steps[4].register.signature = "fresh"
  f.steps[5].sample.gateCount = 6
end, 4, "expected refused")

H.test("the runner fails a malformed fixture", function()
  local f = base()
  f.steps[2].expect.rejected.code = "NOPE"
  local r = run(f)
  H.assertEqual(r.passed, 0, "passed")
  H.assert(has(r.problems, "shape"), joined(r.problems))
end)

H.test("a gate built from { throw } raises exactly its message", function()
  local f = base()
  f.steps = { { emit = { type = "d" }, expect = { rejected = { code = "SISO_GATE_THREW", cause = "d failed", at = { "d" } } } } }
  local r = run(f)
  H.assert(r.passed == 1, joined(r.problems))
end)

H.finish("fixtures")
