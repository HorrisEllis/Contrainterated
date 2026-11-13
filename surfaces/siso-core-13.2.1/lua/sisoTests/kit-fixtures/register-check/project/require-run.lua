-- Fixture: requires kit.run as a module, whatever arguments this script
-- was given, and prints what it got. Nothing may run.
local R = require("kit.run")
print("module " .. type(R.run) .. " args=" .. #arg)
