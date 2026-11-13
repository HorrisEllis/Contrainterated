--[[
kit.helper — the per-gate test API (contract kit-api; phased-spec
references/gate-tests.md). Generalised from jää 2.14.4
(sisoTests/helper.lua), pure gates only: a gate is a core Gate,
Gate(signature, transform, shape), called as `transform(event)`.

  local helper = require("kit.helper")
  local g = helper.gate("app:parse")     -- the shipped gate, from the register

  helper.accept("label", g, helper.event("app:parse", {…}), { helper.event("app:parsed", {…}) })
  helper.abort ("label", g, event, { helper.error("app:parse", "BAD_INPUT") })
  helper.drop  ("label", g, event)
  helper.guard ("label", g, event, {})   -- a bound guard: a drop or an abort
  helper.done()

THE REGISTER. helper.gate(signature) reads the project's register module:
`register`, or the module named by the KIT_REGISTER environment variable
(found on package.path). It returns a list of gate module names; each
module returns a core Gate whose `shape` declares
  { emits = { types… }, rejects = { types… }, classifier = true? }.
helper.use_register(name) reads another register (the kit's own suite
does, for its fixtures; a project's gate test never should). Modules are
loaded once, as `require` would, and kept in package.loaded. The helper
remembers the file each module it loads came from (package.searchpath on
package.path), so done() can name the file the register resolved to.

ERROR EVENTS. A gate's failure is an event (SISO Rule 1):
  { type = "error", data = { source, code, message, detail? } }
with string source, code and message and no other field.
helper.error(source, code, detail?) matches one on source and code (and
detail, when given, by canonical rendering) and requires a string message
without comparing it: tests assert codes, never messages.

EVERY CASE
  - runs only the gate: `g.transform(event)` is called directly with the
    event as its only argument: no stream;
  - requires the input to be an Event of the gate's own type, and the
    gate to declare its shape;
  - requires what the gate returns to be what the stream delivers (core-
    api): nil, an Event, or a list of Events (keys exactly 1..n); anything
    else the stream would reject (SISO_NOT_EVENT). A raise is a rejection
    too (SISO_GATE_THREW), and so is an event of the gate's own type
    (SISO_SELF_LOOP): each fails the case;
  - checks that the input was not changed (kit.canon before and after);
  - runs the gate twice and requires the same output (in-process
    determinism);
  - checks every emitted `error` event keeps the convention above;
  - encodes the output as canonical JSON (kit.data; each Event as
    { type, data }). Output that does not encode fails the case (events
    cross boundaries, SISO "polyglot"), and so does output whose
    canonical JSON differs from the expected events';
  - checks the output against the declared shape:
      accept — at least one event; the set of emitted types equals
               `emits`; a classifier emits exactly one event, of a type
               in `emits`;
      abort  — at least one event, every type in `rejects`;
      drop   — no events;
      guard  — no events, or every type in `rejects`;
  - compares the whole output, in order, with `expected` (kit.canon: 1
    and 1.0 differ, an Event differs from a plain table, an empty array
    from an empty object).

helper.check(kind, g, event, expected) → ok, message, shapeKey, outputJson
gives the verdict without counting it. The case functions count it
through the shared harness (code/harness.lua).

helper.done(suite?) prints, before the harness's final line:
  gates: <signature>[,<signature>…]   every gate obtained by helper.gate
  shape: accepted=<n> distinct=<d> rejections=<r> keys=<k1;…>
  register: <module> <path>           the register module gates were read
                                      from and the file the helper loaded
                                      it from; <path> is "(none)" when the
                                      helper did not load it from a file
                                      (never read, or already in
                                      package.loaded when it was asked for)
  digest: <sha256 hex>                of every case's canonical-JSON
                                      output, in case order (a failed case
                                      adds "FAILED <label>")
and exits. Only cases run through accept/abort/drop/guard enter the
digest. code/kit/census.lua checks the register line at run time: the
module must be the census's own register and the path the file the census
read it from. code/kit/run.lua runs each gate test in two processes and fails
when the digests differ: Lua seeds string hashing per process, so output
that follows `pairs` order can pass the in-process repeat and still
change between runs (kit.digest).
]]

local H = require("harness")
local canon = require("kit.canon")
local data = require("kit.data")
local sha256 = require("kit.sha256")
local Event = require("core.event")

local helper = {}

-- ── state of one test process ─────────────────
-- A gate test is one process; this is its record, never a gate's.

local registerModule = os.getenv("KIT_REGISTER") or "register"
local gateCache = nil
local loadedFrom = {}     -- module name → the file the helper loaded it from
local obtained = {}       -- signature → true
local obtainedList = {}
local records = {}        -- { kind, key, json, failed }

-- ── building inputs and expectations ──────────

function helper.event(eventType, eventData)
  return Event(eventType, eventData)
end

local ErrorMatcher = { __name = "kit.helper.error" }

function helper.error(source, code, detail)
  return setmetatable({ source = source, code = code, detail = detail }, ErrorMatcher)
end

-- ── obtaining the gate ────────────────────────

-- Load a module by name as `require` does (once, kept in package.loaded),
-- from package.path. The register and its gates are named at run time,
-- so they are found here rather than through a literal require.
local function loadModule(name)
  local have = package.loaded[name]
  if have ~= nil then return have end
  local path, why = package.searchpath(name, package.path)
  if not path then error("helper: module '" .. name .. "' not found:" .. why, 0) end
  local chunk, err = loadfile(path)
  if not chunk then error("helper: " .. tostring(err), 0) end
  local value = chunk(name, path)
  if value == nil then value = true end
  package.loaded[name] = value
  loadedFrom[name] = path
  return value
end

-- Read gates from another register module (the kit's own suite only).
function helper.use_register(moduleName)
  registerModule = moduleName
  gateCache = nil
end

function helper.register_module()
  return registerModule
end

-- The file the helper loaded the current register from, or nil when it
-- has not loaded it from a file.
function helper.register_path()
  return loadedFrom[registerModule]
end

local function loadGates()
  if gateCache then return gateCache end
  local list = loadModule(registerModule)
  if type(list) ~= "table" then
    error("helper: register module '" .. registerModule .. "' does not return a list of gate modules", 0)
  end
  local bySig = {}
  for _, name in ipairs(list) do
    local g = loadModule(name)
    if type(g) ~= "table" or type(g.signature) ~= "string" or type(g.transform) ~= "function" then
      error("helper: module '" .. tostring(name) .. "' in " .. registerModule .. " is not a gate", 0)
    end
    if bySig[g.signature] then
      error("helper: signature '" .. g.signature .. "' registered twice in " .. registerModule, 0)
    end
    bySig[g.signature] = g
  end
  gateCache = bySig
  return bySig
end

function helper.gate(signature)
  local g = loadGates()[signature]
  if not g then
    error("helper: gate '" .. tostring(signature) .. "' is not in register " .. registerModule, 0)
  end
  if not obtained[signature] then
    obtained[signature] = true
    obtainedList[#obtainedList + 1] = signature
  end
  return g
end

-- ── checking one case ─────────────────────────

local function set(list)
  local s = {}
  for _, v in ipairs(list or {}) do s[v] = true end
  return s
end

local function sortedKeys(s)
  local ks = {}
  for k in pairs(s) do ks[#ks + 1] = k end
  table.sort(ks)
  return ks
end

-- A transform's return as a list of Events, or nil and why the stream
-- would reject it (core-api GATE).
local function asList(result)
  if result == nil then return {} end
  if Event.is(result) then return { result } end
  if type(result) ~= "table" then
    return nil, "gate returned a " .. type(result) .. ", not nil, an Event or a list of Events"
      .. " (the stream rejects it: SISO_NOT_EVENT)"
  end
  local n, count = #result, 0
  for _ in pairs(result) do count = count + 1 end
  if count ~= n then
    return nil, "gate returned a table whose keys are not exactly 1..n (holes or named keys;"
      .. " the stream rejects it: SISO_NOT_EVENT)"
  end
  local list = {}
  for i = 1, n do
    if not Event.is(result[i]) then
      return nil, "element " .. i .. " of the result is not an Event (the stream rejects it: SISO_NOT_EVENT)"
    end
    list[i] = result[i]
  end
  return list
end

-- Canonical JSON of a list of events: each as { type, data }.
local function eventsJson(list)
  local plain = data.array({})
  for i, e in ipairs(list) do
    plain[i] = { type = e.type, data = e.data }
  end
  return data.encode(plain)
end
helper.eventsJson = eventsJson

local ERROR_FIELDS = { source = true, code = true, message = true, detail = true }

-- The error-event convention, for an emitted event of type "error".
local function errorProblem(e)
  local d = e.data
  if type(d) ~= "table" then return "an error event has no data table" end
  for k in pairs(d) do
    if not ERROR_FIELDS[k] then return "an error event carries an unexpected field '" .. tostring(k) .. "'" end
  end
  if type(d.source) ~= "string" then return "an error event has no string source" end
  if type(d.code) ~= "string" then return "an error event has no string code" end
  if type(d.message) ~= "string" then return "an error event has no string message" end
  return nil
end

local function matches(actual, expected)
  if getmetatable(expected) == ErrorMatcher then
    if actual.type ~= "error" then
      return false, "expected an error event, got '" .. tostring(actual.type) .. "'"
    end
    local d = actual.data
    if d.source ~= expected.source then
      return false, "error source: expected " .. H.show(expected.source) .. ", got " .. H.show(d.source)
    end
    if d.code ~= expected.code then
      return false, "error code: expected " .. H.show(expected.code) .. ", got " .. H.show(d.code)
    end
    if expected.detail ~= nil then
      local want, got = canon.try(expected.detail), canon.try(d.detail)
      if want == nil or want ~= got then
        return false, "error detail: expected " .. tostring(want) .. ", got " .. tostring(got)
      end
    elseif d.detail ~= nil then
      return false, "error event carries an unexpected detail"
    end
    return true
  end
  local a, b = canon.try(actual), canon.try(expected)
  if b == nil then return false, "the expected value is not data" end
  if a ~= b then return false, "expected " .. b .. ", got " .. tostring(a) end
  local ja = eventsJson({ actual })
  local jb, jerr = eventsJson({ expected })
  if not jb then return false, "the expected event is not canonical JSON: " .. jerr end
  if ja ~= jb then return false, "canonical JSON differs: expected " .. jb .. ", got " .. tostring(ja) end
  return true
end

local KINDS = { accept = true, abort = true, drop = true, guard = true }

-- The shape key of a case, or nil and why it breaks the declared shape.
local function checkShape(kind, g, out)
  local shape = g.shape
  local emits, rejects = set(shape.emits), set(shape.rejects)
  if kind == "accept" then
    if #out == 0 then return nil, "an accepted case emitted nothing (a zero output is a drop)" end
    if shape.classifier then
      if #out ~= 1 then return nil, "a classifier case must emit exactly one event, got " .. #out end
      if not emits[out[1].type] then
        return nil, "classifier emitted '" .. tostring(out[1].type) .. "', not in shape.emits"
      end
      return "classifier"
    end
    local got = {}
    for _, e in ipairs(out) do got[e.type] = true end
    local gotKey = table.concat(sortedKeys(got), "|")
    local wantKey = table.concat(sortedKeys(emits), "|")
    if gotKey ~= wantKey then
      return nil, "emitted types {" .. gotKey .. "} differ from shape.emits {" .. wantKey .. "}"
    end
    return gotKey
  end
  if kind == "drop" then
    if #out ~= 0 then return nil, "a drop must emit nothing, got " .. #out .. " event(s)" end
    return "drop"
  end
  if kind == "abort" and #out == 0 then
    return nil, "an abort must emit at least one rejection event"
  end
  for _, e in ipairs(out) do
    if not rejects[e.type] then
      return nil, kind .. " emitted '" .. tostring(e.type) .. "', not in shape.rejects"
    end
  end
  return kind
end

local function typeList(v)
  if type(v) ~= "table" then return false end
  for _, x in ipairs(v) do if type(x) ~= "string" then return false end end
  return true
end

--[[
helper.check(kind, g, event, expected) → ok, message, shapeKey, outputJson
The whole verdict for one case, without counting it.
]]
function helper.check(kind, g, event, expected)
  if not KINDS[kind] then return false, "unknown case kind '" .. tostring(kind) .. "'" end
  if type(g) ~= "table" or type(g.transform) ~= "function" then
    return false, "not a gate (no transform)"
  end
  local shape = g.shape
  if type(shape) ~= "table" or not typeList(shape.emits) or not typeList(shape.rejects) then
    return false, "gate '" .. tostring(g.signature) .. "' declares no shape { emits = {…}, rejects = {…} }"
  end
  if not Event.is(event) then return false, "the input is not an Event" end
  if event.type ~= g.signature then
    return false, "the input type '" .. tostring(event.type) .. "' is not the gate's signature '"
      .. tostring(g.signature) .. "'"
  end
  local before, err = canon.try(event)
  if not before then return false, "input: " .. err end

  local ok1, r1 = pcall(g.transform, event)
  if not ok1 then return false, "gate raised: " .. tostring(r1) .. " (the stream rejects it: SISO_GATE_THREW)" end
  if canon.try(event) ~= before then return false, "gate mutated its input event" end
  local out1, why = asList(r1)
  if not out1 then return false, why end
  for _, e in ipairs(out1) do
    if e.type == g.signature then
      return false, "emits its own type '" .. tostring(e.type) .. "' (the stream rejects a self-loop: SISO_SELF_LOOP)"
    end
  end
  local render1, rerr = canon.try(out1)
  if not render1 then return false, "output: " .. rerr end

  local ok2, r2 = pcall(g.transform, event)
  if not ok2 then return false, "gate raised on the second run: " .. tostring(r2) end
  if canon.try(event) ~= before then return false, "gate mutated its input event (second run)" end
  local out2 = asList(r2)
  if not out2 or canon.try(out2) ~= render1 then
    return false, "gate is not deterministic: two runs gave different outputs"
  end

  for i, e in ipairs(out1) do
    if e.type == "error" then
      local p = errorProblem(e)
      if p then return false, "event " .. i .. ": " .. p end
    end
  end

  local outJson, jerr = eventsJson(out1)
  if not outJson then return false, "output is not canonical JSON: " .. jerr end

  local key, shapeErr = checkShape(kind, g, out1)
  if not key then return false, shapeErr end

  expected = expected or {}
  if type(expected) ~= "table" then return false, "expected must be a list" end
  if #out1 ~= #expected then
    return false, "expected " .. #expected .. " event(s), got " .. #out1 .. ": " .. render1
  end
  for i = 1, #expected do
    local good, msg = matches(out1[i], expected[i])
    if not good then return false, "event " .. i .. ": " .. msg end
  end
  return true, nil, key, outJson
end

-- ── counted cases and the summary ─────────────

local function case(kind, label, g, event, expected)
  H.test("[" .. kind .. "] " .. label, function()
    local ok, msg, key, outJson = helper.check(kind, g, event, expected)
    if not ok then
      records[#records + 1] = { kind = kind, failed = true, json = "FAILED " .. label }
      H.fail(msg)
    end
    records[#records + 1] = { kind = kind, key = key, json = outJson }
  end)
end

function helper.accept(label, g, event, expected) case("accept", label, g, event, expected) end
function helper.abort(label, g, event, expected) case("abort", label, g, event, expected) end
function helper.drop(label, g, event) case("drop", label, g, event, {}) end
function helper.guard(label, g, event, expected) case("guard", label, g, event, expected) end

-- The summary of the recorded cases (what done() prints).
function helper.summary()
  local accepted, rejections, keys, outputs = 0, 0, {}, {}
  for _, r in ipairs(records) do
    outputs[#outputs + 1] = r.json
    if r.failed then
      -- counted by the harness as a failure; no shape
    elseif r.kind == "accept" then
      accepted = accepted + 1
      keys[r.key] = true
    else
      rejections = rejections + 1
    end
  end
  local ks = sortedKeys(keys)
  return {
    accepted = accepted,
    distinct = #ks,
    rejections = rejections,
    keys = table.concat(ks, ";"),
    gates = table.concat(obtainedList, ","),
    register = registerModule,
    registerPath = loadedFrom[registerModule] or "(none)",
    digest = sha256.hex(table.concat(outputs, "\n")),
  }
end

function helper.done(suite)
  if not suite then
    suite = (arg and arg[0] or "gate"):match("([^/\\]+)%.test%.lua$") or "gate"
  end
  local s = helper.summary()
  print("gates: " .. s.gates)
  print("shape: accepted=" .. s.accepted .. " distinct=" .. s.distinct
    .. " rejections=" .. s.rejections .. " keys=" .. s.keys)
  print("register: " .. s.register .. " " .. s.registerPath)
  print("digest: " .. s.digest)
  H.finish(suite)
end

return helper
