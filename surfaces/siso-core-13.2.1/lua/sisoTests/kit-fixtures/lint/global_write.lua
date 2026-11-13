local function f(event)
  counter = (counter or 0) + 1
  return event
end
return f
