-- `require` passed to a function, inside a function body.
local function with(loader, name) return loader(name) end
local function f(event)
  return with(require, event.data.module)
end
return f
