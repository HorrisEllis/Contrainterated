-- `require` under another name: the call through the alias is not read.
local load = require
local Event = load("base.event")
return Event
