--[[ This module does not require("runner"); it only says so.
math.random is mentioned here too. ]]
-- require("io.x") in a line comment
local s = "--[[ not a comment ]] require(x)"
local t = [==[ require("runner") math.random() ]==]
return s .. t
