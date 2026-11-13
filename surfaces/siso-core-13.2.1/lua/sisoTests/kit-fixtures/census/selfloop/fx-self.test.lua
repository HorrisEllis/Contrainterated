-- Fixture per-gate test for fx:self (KIT_REGISTER=kit-fixtures.census.register_self).
-- It passes: no case returns fx:self; the declaration alone is the problem.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
local g = helper.gate("fx:self")

helper.accept("an integer", g, helper.event("fx:self", { n = 4 }), { helper.event("fx:self_done", { n = 4 }) })
helper.abort("a float → BAD_ARGUMENT", g, helper.event("fx:self", { n = 0.5 }), { helper.error("fx:self", "BAD_ARGUMENT") })
helper.done()
