-- Fixture per-gate test for fx:scatter (under KIT_REGISTER=
-- kit-fixtures.helper.register_scatter). The expected list is built in this
-- process with the same `pairs` loop, so every run passes on its own; only
-- the digests of two processes disagree. A fixture, not a model: a gate
-- test takes its expected values from outside the gate.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
local g = helper.gate("fx:scatter")

local t = {}
for i = 1, 24 do t["k" .. i] = i end
local expected = {}
for k, v in pairs(t) do expected[#expected + 1] = helper.event("fx:part", { key = k, v = v }) end

helper.accept("one event per key, in pairs order", g, helper.event("fx:scatter", {}), expected)
helper.done()
