local calls = 0
local function f(event)
  calls = calls + 1
  return event
end
return f
