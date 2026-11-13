-- fixtures-rejection-copy: the runner (code/fixtures/run.lua) judges each
-- batch's rejection with observer-seam's rejection_copy_problem, not by
-- identity (core 13, THE REJECTION).
--
-- It must hold whatever the core in the workspace shows its observers, so
-- the fixtures run on a test-local wrapper over core.stream that decides
-- what its observers are shown: it collects the real stream's batches,
-- lets emit return or raise, then hands each wrapped observer the batch
-- with a rejection built from the one raised (a read-only copy, the raised
-- table itself, or a faulty copy), and re-raises the same table. The copy
-- it shows is plain, as THE REJECTION requires since core 13.0.0:
-- getmetatable answers false on it and on every table in it.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local data = require("kit.data")
local Stream = require("core.stream")
local run = require("fixtures.run")

local function joined(list) return table.concat(list, "; ") end

-- ── what an observer may be shown ───────────────────────────────

-- immutable "readonly:" sentinels (data.null) pass through as themselves
local function isSentinel(v)
  local mt = getmetatable(v)
  return type(mt) == "string" and mt:sub(1, 9) == "readonly:"
end

-- a plain copy (observer-seam THE REJECTION): the raised table's own
-- fields (what `pairs` yields), at every depth, without its metatable;
-- getmetatable answers false on the copy and on every table in it.
-- `writable` leaves out the read-only guard and nothing else.
local function plainCopy(t, writable)
  if type(t) ~= "table" or isSentinel(t) then return t end
  local inner = {}
  for k, v in pairs(t) do inner[k] = plainCopy(v, writable) end
  if writable then return setmetatable(inner, { __metatable = false }) end
  return setmetatable({}, {
    __metatable = false,
    __index = inner,
    __newindex = function() error("read-only", 2) end,
    __pairs = function() return next, inner, nil end,
    __len = function() return #inner end,
  })
end

local function readOnly(t) return plainCopy(t, false) end

local function writableCopy(t)
  local c = {}
  for k, v in pairs(t) do c[k] = v end
  return c
end

local shows = {
  copy = function(raised) return readOnly(raised) end,
  itself = function(raised) return raised end,
  changedMessage = function(raised)
    local c = writableCopy(raised)
    c.message = tostring(c.message) .. " (changed)"
    return readOnly(c)
  end,
  writable = function(raised) return plainCopy(raised, true) end,
}

-- a Stream constructor over core.stream whose observers are shown
-- show(raised) as the rejection; `seen` collects every rejection shown
local function wrapped(show, seen)
  return function()
    local real = Stream()
    local pending, observers = {}, {}
    real:attach(function(b) pending[#pending + 1] = b end)
    local w = {}
    function w:register(g) return real:register(g) end
    function w:takeResidue() return real:takeResidue() end
    function w:sampleHere() return real:sampleHere() end
    function w:attach(f) observers[#observers + 1] = f end
    function w:detach(f)
      for i, g in ipairs(observers) do if g == f then table.remove(observers, i); return end end
      error("not attached", 2)
    end
    function w:emit(e)
      for i = #pending, 1, -1 do pending[i] = nil end
      local ok, err = pcall(real.emit, real, e)
      for _, b in ipairs(pending) do
        for _, f in ipairs(observers) do
          local shown = { outcome = b.outcome, records = b.records }
          if b.outcome == "rejected" then
            shown.rejection = show(err)
            if seen then seen[#seen + 1] = { shown = shown.rejection, raised = err } end
          end
          f(shown)
        end
      end
      if not ok then error(err, 0) end
    end
    return w
  end
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
    decoded[name] = assert(data.decode(text))
  end
  return decoded[name]
end

-- the indices of a fixture's steps that expect a rejection
local function rejectedSteps(f)
  local list = {}
  for i, st in ipairs(f.steps) do
    if type(st.expect) == "table" and st.expect.rejected ~= nil then list[#list + 1] = i end
  end
  return list
end

local totalRejected = 0
for _, name in ipairs(files) do totalRejected = totalRejected + #rejectedSteps(load(name)) end

-- every rejected step fails with `needle`, every other step passes
local function failsEachRejection(show, needle)
  local bad = {}
  for _, name in ipairs(files) do
    local f = load(name)
    local rej = rejectedSteps(f)
    local r = run(f, wrapped(show))
    if r.passed ~= r.total - #rej then
      bad[#bad + 1] = name .. ": " .. r.passed .. "/" .. r.total .. " passed, expected " .. (r.total - #rej)
    end
    for _, i in ipairs(rej) do
      local found = false
      for _, s in ipairs(r.problems) do
        if s:find("steps[" .. i .. "]", 1, true) == 1 and s:find(needle, 1, true) then found = true end
      end
      if not found then bad[#bad + 1] = name .. " step " .. i .. ": no '" .. needle .. "' in " .. joined(r.problems) end
    end
    for _, s in ipairs(r.problems) do
      if not s:find(needle, 1, true) then bad[#bad + 1] = name .. ": unrelated problem " .. s end
    end
  end
  return bad
end

H.test("the suite has rejections to judge", function()
  H.assert(#files >= 15, "fixtures/ holds " .. #files .. " files")
  H.assert(totalRejected > 0, "no fixture step expects a rejection")
end)

H.test("accepted: every fixture passes when observers are shown a read-only copy", function()
  local seen, bad = {}, {}
  for _, name in ipairs(files) do
    local r = run(load(name), wrapped(shows.copy, seen))
    if r.passed ~= r.total or #r.problems > 0 then
      bad[#bad + 1] = name .. " " .. r.passed .. "/" .. r.total .. ": " .. joined(r.problems)
    end
  end
  H.assert(#bad == 0, joined(bad))
  -- the runner was really shown copies, one per rejected step
  H.assertEqual(#seen, totalRejected, "rejections shown")
  for _, x in ipairs(seen) do
    H.assert(not rawequal(x.shown, x.raised), "the wrapper showed the raised table")
    H.assert(not pcall(function() x.shown.code = "X" end), "the wrapper's copy accepts assignment")
    H.assert(getmetatable(x.shown) == false, "the wrapper's copy answers getmetatable with " .. tostring(getmetatable(x.shown)))
  end
end)

H.test("rejected: a batch carrying the raised table itself fails each rejected step", function()
  local bad = failsEachRejection(shows.itself, "very table raised")
  H.assert(#bad == 0, joined(bad))
end)

H.test("rejected: a copy whose message differs fails each rejected step", function()
  local bad = failsEachRejection(shows.changedMessage, "differs from the one raised at 'message'")
  H.assert(#bad == 0, joined(bad))
end)

H.test("rejected: a copy that accepts assignment fails each rejected step", function()
  local bad = failsEachRejection(shows.writable, "accepts assignment")
  H.assert(#bad == 0, joined(bad))
end)

H.test("an inline fixture: the copy is accepted, the raised table refused", function()
  local f = {
    format = 1, name = "inline", covers = { "K5" },
    gates = { { signature = "a", does = { emitOne = { type = "b" } } }, { signature = "b", does = { throw = "b failed" } } },
    steps = { { emit = { type = "a" }, expect = { rejected = { code = "SISO_GATE_THREW", cause = "b failed", at = { "a", "b" } } } } },
  }
  local r = run(f, wrapped(shows.copy))
  H.assert(r.passed == 1 and #r.problems == 0, joined(r.problems))
  r = run(f, wrapped(shows.itself))
  H.assert(r.passed == 0, "the raised table itself was accepted")
end)

H.finish("fixtures-rejection-copy")
