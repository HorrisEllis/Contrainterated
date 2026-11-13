-- Fixture per-gate test for fx:echo (the clean case): reads the project's
-- register through kit.helper and nothing else. Expected values by hand.
local root = (arg and arg[0] or ""):match("^(.*)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
local g = helper.gate("fx:echo")

helper.accept("echoes hi", g, helper.event("fx:echo", { text = "hi" }), { helper.event("fx:echoed", { text = "hi" }) })
helper.accept("echoes empty", g, helper.event("fx:echo", { text = "" }), { helper.event("fx:echoed", { text = "" }) })
helper.abort("number → BAD_ARGUMENT", g, helper.event("fx:echo", { text = 1 }), { helper.error("fx:echo", "BAD_ARGUMENT") })
helper.done()
