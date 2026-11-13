-- A call inside require's argument: a dynamic require (its argument is
-- not a literal), but require itself is the callee, not a value.
local function name(s) return "base." .. s end
return require(name("event"))
