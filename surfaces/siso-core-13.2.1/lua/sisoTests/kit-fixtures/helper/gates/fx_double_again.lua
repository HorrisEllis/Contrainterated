-- Fixture gate with fx_double's signature (register_twice.lua only).
local Gate = require("core.gate")

return Gate("fx:double", function() return nil end, { emits = {}, rejects = {} })
