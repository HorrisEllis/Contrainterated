-- Loads kit.lint by require, as a test or a consumer would, and reports
-- what it got. Run with any number of arguments: the lint must not run.
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. package.path
local L = require("kit.lint")
print("module " .. type(L) .. " " .. type(L.lintFile) .. " args=" .. select("#", ...))
