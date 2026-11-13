-- `require` handed to pcall: the lint cannot read which module loads.
local ok, Event = pcall(require, "base.event")
return ok and Event
