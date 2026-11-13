-- Fixture: a per-gate test that plants the register kit.helper will read
-- (package.loaded). It passes; the census refuses it.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
package.loaded[os.getenv("KIT_REGISTER") or "register"] = { "kit-fixtures.census.gates.fx_route" }
local helper = require("kit.helper")
local g = helper.gate("fx:route")

helper.accept("3 is small", g, helper.event("fx:route", { n = 3 }), { helper.event("fx:small", { n = 3 }) })
helper.done()
