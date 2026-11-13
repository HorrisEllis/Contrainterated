-- Fixture: a per-gate test whose output has no register line (it hides
-- the helper's, as a helper older than the line would). It passes on its
-- own; the census refuses it.
local root = (arg and arg[0] or ""):match("^(.-)noline/") or ""
package.path = root .. "code/?.lua;" .. package.path
local show = print
print = function(line, ...)
  if type(line) == "string" and line:match("^register: ") then return end
  show(line, ...)
end
local helper = require("kit.helper")
local g = helper.gate("fx:echo")

helper.accept("echoes hi", g, helper.event("fx:echo", { text = "hi" }), { helper.event("fx:echoed", { text = "hi" }) })
helper.done()
