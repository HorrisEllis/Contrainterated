-- A gate reaching the stream and the log through the base layer, which
-- the fixture config admits only base.event and base.gate from.
local Event = require("base.event")
local Stream = require("base.stream")
local StreamLog = require("base.streamlog")
return function(e) return Event("x", { s = Stream, l = StreamLog, e = e }) end
