-- Fixture per-gate test for fx:cyc_a (KIT_REGISTER=kit-fixtures.census.register_cycle).
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
local g = helper.gate("fx:cyc_a")

helper.accept("passes v on", g, helper.event("fx:cyc_a", { v = 1 }), { helper.event("fx:cyc_b", { v = 1 }) })
helper.done()
