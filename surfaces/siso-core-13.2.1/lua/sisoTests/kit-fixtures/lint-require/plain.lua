-- Plain requires stay clean: called directly, as an argument's call,
-- as a tail call, and a key or string named require.
local Event = require("base.event")
local Gate = require 'base.gate'
local function wrap(m) return m end
local Lib = wrap(require("lib.util"))
local function f(event)
  local note = "pcall(require, name) in a string is not code"
  return Event("x:out", { gate = Gate, lib = Lib, note = note, require = 1 })
end
local function g() return require("lib.util") end
return { f = f, g = g }
