local function f(event)
  local ks = {}
  for k in pairs(event.data) do ks[#ks + 1] = k end
  return ks
end
return f
