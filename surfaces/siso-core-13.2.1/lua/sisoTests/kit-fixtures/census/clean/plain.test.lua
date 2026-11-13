-- Fixture plain suite (not a per-gate test by run.lua's rule): neither a
-- gate's test nor an orphan.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local H = require("harness")

H.test("one", function() H.assertEqual(1 + 1, 2) end)
H.finish("plain")
