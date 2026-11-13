-- Fixture per-gate test under a fixtures directory: run.lua never enters
-- it, so neither does the census (it would be an orphan otherwise).
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
local g = helper.gate("fx:gone")

helper.drop("never runs", g, helper.event("fx:gone", {}))
helper.done()
