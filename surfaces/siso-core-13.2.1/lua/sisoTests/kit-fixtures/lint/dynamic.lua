local name = "base." .. "event"
local m = require(name)                     -- a variable
local n = require("base." .. name)          -- a literal that is not the whole argument
return { m, n, require = true }             -- a key named require is not a call
