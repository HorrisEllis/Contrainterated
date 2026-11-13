--[[
contract-fake-core — a throwaway implementation that exists only so the
contract tests can show each contract check passing on something that
honours the contract, and failing on each departure it names.

It is NOT the core and NOT a reference for it: no attention to cost, no
lint conformance, not checked against the JS oracle. The stream phases
build code/core/ from the contracts and the 11.1 oracle, never from this.

  local fake = require("contract-fake-core")
  local core = fake.new()                   -- honours core-api and observer-seam
  local core = fake.new({ skipNonEvents = true })   -- 11.1's X1
Switches, each reproducing one departure:
  skipNonEvents   non-Event list members are skipped (11.1 X1)
  acceptAnyEntry  the entry door takes any value (11.1)
  countIds        ids continue across propagations (11.1 R1)
  sampleCount     sampleHere carries eventCount (11.1 R1)
  noFreeze        events are not read-only (11.1 R3)
  observeDuring   observers are called per delivery, during the
                  propagation (11.1's observer)
  unprotected     an observer's raise escapes emit
  sharedBatch     every observer gets the same batch table
  shareFrozen     samples and observer records hand out the stream's own
                  frozen objects instead of copies (12.0's X6)
  writableAtBirth Event builds a plain table; only the stream freezes it
                  (12.0: an event was writable until it entered)
  sharedRejection every observer is shown the very rejection emit raises
                  (12.0's C-2)
  tableMeta       events answer getmetatable with a writable table, a
                  channel for state (12.0.1 and 13.0.0-rc1)
  entryAlias      entry keeps the entering code's own event (13.0.0-rc1)
  richRejection   an observer's rejection copy answers the rejection's
                  metatable, the host's own (13.0.0-rc1)

Test-only: it reads real metatables with `debug.getmetatable`, which no
core code may do.
]]

local fake = {}

function fake.new(opts)
  opts = opts or {}
  local core = {}

  local EventMeta = { __name = "fake.event" }
  if not opts.tableMeta then EventMeta.__metatable = "siso.event" end
  local EVENT_MARK = opts.tableMeta and EventMeta or "siso.event"
  local isProxy = setmetatable({}, { __mode = "k" })
  local origOf = setmetatable({}, { __mode = "k" })
  local realOf = setmetatable({}, { __mode = "k" })

  local function isSentinel(orig)
    return (type(orig) == "string" and orig:sub(1, 9) == "readonly:")
      or (type(orig) == "table" and rawget(orig, "__readonly") == true)
  end

  local freeze  -- defined below; Event freezes what it builds (core 13)

  local Event = setmetatable({}, {
    __call = function(_, t, d)
      if d == nil then d = {} end
      local e = setmetatable({ type = t, data = d }, EventMeta)
      if opts.writableAtBirth then return e end
      return freeze(e)
    end,
  })
  function Event.is(v) return getmetatable(v) == EVENT_MARK end

  freeze = function(v)
    if opts.noFreeze or type(v) ~= "table" or isProxy[v] then return v end
    local orig = getmetatable(v)
    if isSentinel(orig) then return v end
    local kids = {}
    for k, x in pairs(v) do kids[k] = freeze(x) end
    local answer = orig
    if answer == nil then answer = false end
    local proxyMeta = {
      __index = kids,
      __newindex = function() error("read-only", 2) end,
      __len = function() return #kids end,
      __pairs = function() return next, kids, nil end,
      __metatable = answer,
    }
    local proxy = setmetatable({}, proxyMeta)
    isProxy[proxy] = true
    origOf[proxy] = orig
    realOf[proxy] = debug.getmetatable(v)
    return proxy
  end

  -- a fresh frozen copy of a frozen value: rebuilt through its read
  -- surface, so a rawset on one copy reaches no other
  local function thaw(v)
    if type(v) ~= "table" then return v end
    local orig, real
    if isProxy[v] then orig, real = origOf[v], realOf[v] else orig, real = getmetatable(v), debug.getmetatable(v) end
    if isSentinel(orig) then return v end
    local t = {}
    for k, x in pairs(v) do t[k] = thaw(x) end
    if real then setmetatable(t, real) end
    return t
  end
  -- a table's own fields at every depth, no metatables (THE REJECTION)
  local function plain(v, seen)
    if type(v) ~= "table" or isSentinel(getmetatable(v)) then return v end
    seen = seen or {}
    if seen[v] then return seen[v] end
    local t = {}
    seen[v] = t
    for k, x in pairs(v) do t[k] = plain(x, seen) end
    return t
  end
  local function own(v)
    if opts.shareFrozen or opts.noFreeze then return v end
    return freeze(thaw(v))
  end

  local function Gate(signature, transform, shape)
    return { signature = signature, transform = transform or function() end, shape = shape }
  end

  local RejMeta = { __tostring = function(r) return r.message end }
  local function reject(t) return setmetatable(t, RejMeta) end

  local S = {}
  S.__index = S
  local function Stream()
    return setmetatable({ gates = {}, n = 0, residue = {}, observers = {}, count = 0 }, S)
  end

  function S:register(g)
    if self.gates[g.signature] ~= nil then error("Signature collision: '" .. tostring(g.signature) .. "'", 0) end
    self.gates[g.signature] = g
    self.n = self.n + 1
  end

  local function batchFor(outcome, rej, recs)
    local copy = {}
    for i, r in ipairs(recs) do
      copy[i] = { eventId = r.eventId, causeId = r.causeId, eventType = r.eventType, claimed = r.claimed, data = own(r.data) }
    end
    local r = rej
    if rej ~= nil and not opts.sharedRejection then
      if opts.richRejection then r = freeze(thaw(rej)) else r = freeze(plain(rej)) end
    end
    return { outcome = outcome, rejection = r, records = copy }
  end

  local function notify(self, outcome, rej, recs)
    local list = {}
    for i, o in ipairs(self.observers) do list[i] = o end
    local shared = batchFor(outcome, rej, recs)
    for _, o in ipairs(list) do
      local b = opts.sharedBatch and shared or batchFor(outcome, rej, recs)
      if opts.unprotected then o(b) else pcall(o, b) end
    end
  end

  local function isList(t)
    local c = 0
    for _ in pairs(t) do c = c + 1 end
    return c == #t
  end

  function S:emit(event)
    local recs = {}
    local function fail(rej)
      if not opts.observeDuring then notify(self, "rejected", rej, recs) end
      error(rej, 0)
    end
    if not opts.acceptAnyEntry and not Event.is(event) then
      fail(reject({ code = "SISO_NOT_EVENT", message = "entry is not an Event (" .. type(event) .. ")" }))
    end
    local base = opts.countIds and self.count or 0
    local entered = opts.entryAlias and freeze(event) or freeze(thaw(event))
    local queue = { { event = entered, depth = 1 } }
    local settled, head, id = {}, 1, 0
    while head <= #queue do
      local item = queue[head]
      head = head + 1
      local cur = item.event
      id = id + 1
      local eventId = base + id
      if opts.countIds then self.count = eventId end
      local g = self.gates[cur.type]
      local rec = { eventId = eventId, causeId = item.causeId, eventType = cur.type, claimed = g ~= nil, data = cur.data }
      recs[#recs + 1] = rec
      if opts.observeDuring then notify(self, "settled", nil, { rec }) end
      if not g then
        settled[#settled + 1] = cur
      else
        if item.depth > self.n then
          fail(reject({ code = "SISO_CYCLE", message = "cycle at '" .. tostring(cur.type) .. "'",
            eventId = eventId, causeId = item.causeId, eventType = cur.type, depth = item.depth }))
        end
        local ok, res = pcall(g.transform, cur)
        if not ok then
          fail(reject({ code = "SISO_GATE_THREW", message = "Gate '" .. tostring(g.signature) .. "' threw: " .. tostring(res),
            eventId = eventId, causeId = item.causeId, eventType = cur.type, cause = res }))
        end
        local outs
        if res == nil then outs = {}
        elseif Event.is(res) then outs = { res }
        elseif type(res) == "table" and not getmetatable(res) and isList(res) then outs = res
        elseif opts.skipNonEvents and type(res) == "table" then outs = res
        else
          fail(reject({ code = "SISO_NOT_EVENT", message = "gate returned a " .. type(res),
            eventId = eventId, causeId = item.causeId, eventType = cur.type }))
        end
        for i = 1, #outs do
          local o = outs[i]
          if not Event.is(o) then
            if not opts.skipNonEvents then
              fail(reject({ code = "SISO_NOT_EVENT", message = "element " .. i .. " is not an Event",
                eventId = eventId, causeId = item.causeId, eventType = cur.type }))
            end
          elseif o.type == g.signature then
            fail(reject({ code = "SISO_SELF_LOOP", message = "gate '" .. tostring(g.signature) .. "' returned its own type",
              eventId = eventId, causeId = item.causeId, eventType = cur.type }))
          else
            queue[#queue + 1] = { event = freeze(o), causeId = eventId, depth = item.depth + 1 }
          end
        end
      end
    end
    for _, e in ipairs(settled) do self.residue[#self.residue + 1] = e end
    if not opts.observeDuring then notify(self, "settled", nil, recs) end
  end

  function S:sampleHere()
    local copy = {}
    for i, e in ipairs(self.residue) do copy[i] = own(e) end
    local s = { residue = copy, gateCount = self.n }
    if opts.sampleCount then s.eventCount = self.count end
    return s
  end

  function S:takeResidue()
    local t = self.residue
    self.residue = {}
    return t
  end

  function S:attach(o)
    if type(o) ~= "function" then error("an observer is a function", 0) end
    for _, x in ipairs(self.observers) do if x == o then error("observer already attached", 0) end end
    self.observers[#self.observers + 1] = o
  end

  function S:detach(o)
    for i, x in ipairs(self.observers) do
      if x == o then table.remove(self.observers, i); return end
    end
    error("observer not attached", 0)
  end

  core.Event, core.Gate, core.Stream = Event, Gate, Stream
  return core
end

return fake
