-- Fixture per-gate test for fx:bare, a gate with no shape
-- (KIT_REGISTER=kit-fixtures.census.register_bare): the helper fails it.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
local g = helper.gate("fx:bare")

helper.accept("passes v on", g, helper.event("fx:bare", { v = 1 }), { helper.event("fx:bared", { v = 1 }) })
helper.done()
