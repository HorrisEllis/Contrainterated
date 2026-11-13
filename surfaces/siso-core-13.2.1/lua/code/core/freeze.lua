--[[
core.freeze — the read-only view the stream gives every event (R3).

Contract core-api EVENT / READ-ONLY, STATELESS THROUGH THE EVENT,
IMMUTABLE SENTINELS and OWNERSHIP, and observer-seam THE REJECTION
(core 13.0.0). Event freezes what it builds; the stream takes its own
copy of the entry (freeze.copy) and freezes every event a gate returns (a
frozen event passes through untouched); inside a propagation a gate
shares its input with the stream (R3). What leaves through the sample
door and the observe door is a copy, the receiver's own: freeze.copy for
events and record data, freeze.plain for an observer's rejection. So a
`rawset` on it reaches nothing else: `rawset` on a proxy writes a raw
field into that proxy table, and a copy shares no proxy with anything.

  local freeze = require("core.freeze")
  freeze(v)             → v frozen: a read-only proxy for a table, v itself
                          for anything else
  freeze.copy(v, memo)  → a fresh frozen copy of v that answers what v
                          answers (memo optional, see freeze.copy)
  freeze.plain(v, memo) → a fresh frozen copy of v's own fields at every
                          depth, answering getmetatable with false
  freeze.isFrozen(v)    → true only for a proxy built here (copies
                          included)

What a frozen table is:
  - An empty proxy whose metatable refuses assignment (__newindex raises
    "read-only: …"). `rawset` on the proxy is not refused: that is the
    substrate concession R3.
  - Reads go through indexing, `#`, `ipairs` and `pairs` to a view built
    once, when the table is frozen: a copy of the original's own entries
    (read raw, with `next`) whose table values are themselves frozen. So
    reading the same field twice gives the same value (rawequal), tables
    included, and nothing is built or written at read time.
  - The view is a copy: writes the outside makes to the original after
    it was frozen do not reach the frozen table.
  - getmetatable answers what getmetatable answered on the original: the
    string "siso.event" for an event, a data mark as it is, `false` for a
    table with none. The proxy's own metatable is never handed out.

STATELESS THROUGH THE EVENT (core 13.0.0): nothing the core holds or
shares is reachable from a frozen table, and nothing here is written
after load. Each proxy has its own metatable, hidden behind
`__metatable`, and its own view; `pairs` iterates with the proxy itself
as state, never the view.

Recognising a frozen table: a key known only to this module (KEY), a
function, so it can hold no field. Indexing a proxy with KEY misses in
its view, whose shared metatable (VIEW_MT, locked: it answers
getmetatable with false) answers the view itself; a plain table answers
nil. A table with a metatable that is not a proxy is asked for KEY by
ordinary indexing, so its own __index (if any) runs and may capture KEY;
plain data has none. That is accepted (core-api: `rawset` past a frozen
table is the substrate concession R3): a captured KEY reaches one
proxy's view, which allows writes past the refusal on that one table,
exactly what `rawset` already gives. It carries no state: KEY is a
function with no fields, and a view answers getmetatable with false and
shares nothing with any other view. (freeze.plain reads through `pairs`,
as observer-seam asks, inside an observer's protected call.)

Passed through untouched, keeping their identity:
  - values that are not tables;
  - an immutable sentinel: a table whose getmetatable answers a string
    beginning "readonly:" (kit.data.null), or the core 12 form, a
    metatable declaring `__readonly = true` (still passed, but that form
    hands out its writable metatable: use the string form);
  - a table already frozen (so an event or data passed on unchanged is
    never wrapped twice).

Keys are kept as they are by freeze and freeze.copy (plain data has
string and number keys; a table used as a key is not frozen, since
freezing it would change the key's identity). freeze.plain copies table
keys too: its copy shares nothing with what it copies.

Cycles and sharing. Event data is plain and acyclic (core-api EVENT),
but freezing does not rely on it: within one call each original table is
handled once (a memo local to that call), so a table reached twice gives
the same proxy both times, and a cycle gives a frozen structure with the
same cycle. Freezing always terminates; nothing detects or rejects a
cycle. The cost of each call grows with the size of what it freezes or
copies, never with history.
]]

-- The key only this module knows: a function, so it can hold no state.
local KEY = function() end

-- Every view's metatable: a miss on KEY answers the view itself, any
-- other miss answers nil. Locked, so a view hands out nothing shared.
local VIEW_MT = {
  __index = function(view, k)
    if rawequal(k, KEY) then return view end
    return nil
  end,
  __metatable = false,
}

-- v's view if v is a proxy built here, else nil.
local function viewOf(v)
  local w = v[KEY]
  if type(w) == "table" and getmetatable(w) == false and rawequal(w[KEY], w) then return w end
  return nil
end

-- Shared metamethods of every proxy. Each finds its view through KEY.
local function refuse(_, k)
  error("read-only: an event and its data cannot be assigned (field '" .. tostring(k) .. "')", 2)
end

local function len(p)
  return #p[KEY]
end

local function step(p, k)
  return next(p[KEY], k)
end

local function iterate(p)
  return step, p, nil
end

-- An immutable sentinel: getmetatable answers "readonly:…", or (core 12)
-- a metatable table declaring __readonly = true.
local function isSentinel(mt)
  local t = type(mt)
  if t == "string" then return mt:sub(1, 9) == "readonly:" end
  return t == "table" and rawget(mt, "__readonly") == true
end

-- A fresh, empty proxy answering `answer`, and its view.
local function newProxy(answer)
  local view = setmetatable({}, VIEW_MT)
  local proxy = setmetatable({}, {
    __index = view,
    __newindex = refuse,
    __len = len,
    __pairs = iterate,
    __metatable = answer,
  })
  return proxy, view
end

--[[
build(v, memo, copying): v is a table; memo maps a table already handled
in this call to its proxy. Freezing (copying false) keeps a frozen table
as it is. Copying rebuilds it: a fresh proxy and view answering the same
metatable, whose entries are what its read surface gives — each key
`pairs` yields, read as indexing reads it (a raw field first, then the
view) — with every table value copied the same way. A copy therefore
shares no proxy with the original or with any other copy, at any depth.
A plain table met while copying is frozen fresh, its frozen tables
copied too. A foreign table's entries are read with `next`; only a table
with a metatable is asked for KEY (see the header).
]]
local function build(v, memo, copying)
  local mt = getmetatable(v)
  local view
  if mt ~= nil then
    if isSentinel(mt) then return v end
    view = viewOf(v)
    if view and not copying then return v end
  end
  local done = memo[v]
  if done ~= nil then return done end

  local answer = mt
  if answer == nil then answer = false end
  local proxy, fresh = newProxy(answer)
  memo[v] = proxy
  if view then
    for k, x in next, view do
      local raw = rawget(v, k)
      if raw ~= nil then x = raw end
      if type(x) == "table" then x = build(x, memo, true) end
      fresh[k] = x
    end
  else
    for k, x in next, v do
      if type(x) == "table" then x = build(x, memo, copying) end
      fresh[k] = x
    end
  end
  return proxy
end

-- plainCopy(v, memo): v's own fields at every depth (what `pairs`
-- yields), keys included, each table a fresh proxy answering false;
-- a "readonly:" sentinel is v itself.
local function plainCopy(v, memo)
  if type(v) ~= "table" then return v end
  local mt = getmetatable(v)
  if type(mt) == "string" and mt:sub(1, 9) == "readonly:" then return v end
  local done = memo[v]
  if done ~= nil then return done end
  -- a string answer (a mark such as "kit.data.array") hands out nothing
  -- and is kept; anything else answers false
  local answer = false
  if type(mt) == "string" then answer = mt end
  local proxy, fresh = newProxy(answer)
  memo[v] = proxy
  for k, x in pairs(v) do
    fresh[plainCopy(k, memo)] = plainCopy(x, memo)
  end
  return proxy
end

local freeze = {}

function freeze.isFrozen(v)
  return type(v) == "table" and getmetatable(v) ~= nil and viewOf(v) ~= nil
end

--[[
freeze.copy(v, memo) → a fresh frozen copy of v, the caller's own: a
table is rebuilt (see build), a sentinel and anything that is not a
table is v itself. `memo` is optional: a table the caller passes to
several copies made for one owner, so a table reached twice among them is
copied once (sharing within one owner's copies is kept; nothing is shared
with the original or with another owner's copies).
]]
function freeze.copy(v, memo)
  if type(v) ~= "table" then return v end
  return build(v, memo or {}, true)
end

--[[
freeze.plain(v, memo) → observer-seam THE REJECTION: a fresh read-only
copy of v made of its own fields at every depth (what `pairs` yields,
keys included), without any metatable table: getmetatable answers false
on the copy and on every table in it, or the string the original
answered (a mark such as "kit.data.array" is kept; a string hands out
nothing). An error object's methods and inherited
fields are not carried. A "readonly:" sentinel passes as itself; a core
12 `__readonly` table is copied as its own fields, since its metatable
is writable. Its cost grows with the size of v.
]]
function freeze.plain(v, memo)
  return plainCopy(v, memo or {})
end

return setmetatable(freeze, {
  __call = function(_, v)
    if type(v) ~= "table" then return v end
    return build(v, {}, false)
  end,
})
