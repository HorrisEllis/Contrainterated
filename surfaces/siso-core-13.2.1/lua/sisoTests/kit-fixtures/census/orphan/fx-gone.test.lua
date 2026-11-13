-- Fixture orphan: a per-gate test (run.lua's rule) for a gate that is not
-- in the register.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path
local helper = require("kit.helper")
local g = helper.gate("fx:gone")

helper.drop("never runs", g, helper.event("fx:gone", {}))
helper.done()
