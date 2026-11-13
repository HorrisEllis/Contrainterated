-- Fixture: a per-gate test by run.lua's rule (kit.helper, done()) that
-- takes its gate from the module instead of helper.gate: not a gate test
-- of the shipped gate as the census counts one.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
local g = require("kit-fixtures.census.gates.fx_start")

helper.accept("asks to double 21", g, helper.event("fx:start", { n = 21 }), { helper.event("fx:double", { x = 21 }) })
helper.done()
