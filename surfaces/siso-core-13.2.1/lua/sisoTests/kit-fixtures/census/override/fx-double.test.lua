-- Fixture: a per-gate test that picks its own register (use_register).
-- It passes; the census refuses it.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
helper.use_register("kit-fixtures.census.register_clean")
local g = helper.gate("fx:double")

helper.accept("doubles 21", g, helper.event("fx:double", { x = 21 }), { helper.event("fx:doubled", { value = 42 }) })
helper.done()
