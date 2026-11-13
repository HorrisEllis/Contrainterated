-- Writes to shared tables that SETTABUP alone does not show.
local rel = require("lib.rel")
local memo = {}
local counter = { n = { v = 0 } }
local slots = { 0 }
local function f(event)
  local k = event.data.k
  memo[k] = true                                  -- computed key on a captured table
  counter.n.v = counter.n.v + 1                   -- a field of a captured table's field
  slots[1] = slots[1] + 1                         -- an integer key
  rel.ORDER[#rel.ORDER + 1] = "x"                 -- another module's table
  string.shout = string.upper                     -- a standard library table
  local fresh = {}
  fresh[k] = 1                                    -- a table made here: not shared
  local alias = memo
  alias.z = 1                                     -- through a move
  table.insert(memo, k)                           -- a standard-library call writing a captured table
  table.sort(slots)
  setmetatable(counter, {})
  table.sort(fresh, rel.cmp)                      -- a comparator from a module: not a write
  local u = require("lib.util")
  u.extra = true                                  -- a module required inside the function
  return fresh
end
return f
