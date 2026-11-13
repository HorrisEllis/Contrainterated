local function f(event)
  return { now = os.time(), event = event }
end
return f
