--[[
core.gate — Gate, a shape that recognises one type of event and
transforms it into others.

Contract core-api GATE (core 12). Port of core 11.1
legacy/js/code/src/Gate.js, in the Lua form.

  local Gate = require("core.gate")
  Gate(signature, transform, shape) → { signature, transform, shape }

`signature` is the unique key the stream routes by. `transform` is a
pure function the stream calls as `gate.transform(event)`, with the
event as its only argument. It returns:
  nil (or nothing)  consumed, no follow-up
  an Event          one follow-up
  a list of Events  a table whose keys are exactly 1..n, each an Event
Anything else (false, a number, a string, a table with holes or named
keys, a list with a member that is not an Event) rejects the
propagation with SISO_NOT_EVENT (12.0; 11.1 skipped it silently).
With no `transform`, the gate consumes and returns nothing (11.1's
default transform). `shape` is an optional declaration for tooling
({ emits = {…}, rejects = {…} }); the stream never reads it.
]]

local function noTransform() end

local function Gate(signature, transform, shape)
  return {
    signature = signature,
    transform = transform or noTransform,
    shape = shape,
  }
end

return Gate
