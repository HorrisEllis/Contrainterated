-- Fixture per-gate test for fx:route, a classifier (run by
-- code/kit/census.lua under KIT_REGISTER=kit-fixtures.census.register_clean).
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
local g = helper.gate("fx:route")

helper.accept("3 is small", g, helper.event("fx:route", { n = 3 }), { helper.event("fx:small", { n = 3 }) })
helper.accept("50 is large", g, helper.event("fx:route", { n = 50 }), { helper.event("fx:large", { n = 50 }) })
helper.done()
