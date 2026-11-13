local cache = {}
local function f(event)
  cache.last = event
  return event
end
return f
