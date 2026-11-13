-- Fixture per-gate test for fx:start (run by code/kit/census.lua under
-- KIT_REGISTER=kit-fixtures.census.register_clean). Expected values by hand.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
local g = helper.gate("fx:start")

helper.accept("asks to double 21", g, helper.event("fx:start", { n = 21 }), { helper.event("fx:double", { x = 21 }) })
helper.accept("asks to double -3", g, helper.event("fx:start", { n = -3 }), { helper.event("fx:double", { x = -3 }) })
helper.done()
