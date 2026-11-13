-- Fixture plain suite (not a gate test): run once by code/kit/run.lua.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local H = require("harness")

H.test("one", function() H.assertEqual(1 + 1, 2) end)
H.test("two", function() H.assert(true) end)
H.finish("plain")
