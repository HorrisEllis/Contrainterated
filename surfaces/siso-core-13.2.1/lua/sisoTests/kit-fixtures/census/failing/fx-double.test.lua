-- Fixture per-gate test for fx:double that expects 2 × 21 = 43, so it
-- fails (run by code/kit/census.lua under
-- KIT_REGISTER=kit-fixtures.census.register_clean). Expected values by hand.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
local g = helper.gate("fx:double")

helper.accept("doubles 21", g, helper.event("fx:double", { x = 21 }), { helper.event("fx:doubled", { value = 43 }) })
helper.accept("doubles -3", g, helper.event("fx:double", { x = -3 }), { helper.event("fx:doubled", { value = -6 }) })
helper.abort("float → BAD_ARGUMENT", g, helper.event("fx:double", { x = 1.5 }), { helper.error("fx:double", "BAD_ARGUMENT") })
helper.done()
