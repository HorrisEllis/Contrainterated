-- Fixture: a plain suite at fx:route's test name, calling a copy of the
-- gate's module directly (no kit.helper): not a per-gate test.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local H = require("harness")
local Event = require("core.event")
local g = require("kit-fixtures.census.gates.fx_route")

H.test("3 is small", function() H.assertEqual(g.transform(Event("fx:route", { n = 3 })).type, "fx:small") end)
H.finish("fx-route")
