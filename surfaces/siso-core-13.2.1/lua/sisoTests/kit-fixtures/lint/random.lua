local function f(event)
  return math.random(6) + #event.data
end
return f
