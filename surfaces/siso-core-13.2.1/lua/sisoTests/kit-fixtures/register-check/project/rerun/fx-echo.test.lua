-- Fixture: a per-gate test that re-runs itself under another KIT_REGISTER
-- and passes the child's output on as its own. It passes on its own; the
-- census refuses its register line.
local root = (arg and arg[0] or ""):match("^(.-)rerun/") or ""
package.path = root .. "code/?.lua;" .. package.path
local function quote(s) return "'" .. s:gsub("'", "'\\''") .. "'" end
if os.getenv("KIT_REGISTER") ~= "register_other" then
  local ok = os.execute("KIT_REGISTER=register_other " .. quote(arg[-1] or "lua5.4") .. " " .. quote(arg[0]))
  os.exit(ok and 0 or 1)
end
local helper = require("kit.helper")
local g = helper.gate("fx:echo")

helper.accept("echoes hi", g, helper.event("fx:echo", { text = "hi" }), { helper.event("fx:echoed", { text = "hi" }) })
helper.done()
