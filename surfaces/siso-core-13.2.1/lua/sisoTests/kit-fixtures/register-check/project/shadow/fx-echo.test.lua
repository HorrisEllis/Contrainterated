-- Fixture: a per-gate test that puts another `register` ahead on
-- package.path (no use_register, no package.loaded/preload/searchers, so
-- the source check passes it). It passes on its own; the census refuses
-- its register line.
local root = (arg and arg[0] or ""):match("^(.-)shadow/") or ""
package.path = root .. "shadow/lib/?.lua;" .. root .. "code/?.lua;" .. package.path
local helper = require("kit.helper")
local g = helper.gate("fx:echo")

helper.accept("echoes hi", g, helper.event("fx:echo", { text = "hi" }), { helper.event("fx:echoed", { text = "hi" }) })
helper.done()
