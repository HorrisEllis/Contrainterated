--[[
kit.digest — the cross-process determinism check (contract kit-api),
as functions code/kit/run.lua and the kit's own suite call. Generalised
from jää 2.14.4 (sisoTests/tools/digest.lua).

  D.parse(output)        the last "digest: <hex>" line of one run, or nil
  D.compare(out1, out2)  → ok, message: both runs printed a digest and
                         the digests are equal
  D.second(cmd)          the shell command for the second process
  D.runTwice(cmd)        runs a shell command in two processes;
                         → ok, message, out1, bothExitOk

kit.helper's done() prints the digest: SHA-256 of the canonical JSON of
every case's output, in case order. Lua 5.4 seeds string hashing per
process, so a gate whose output follows `pairs` order gives the same
output twice in one process (the helper's in-process repeat passes) and
a different output in another. Only a second process shows it.

The seed mixes time(NULL) with the addresses of a stack variable and of
the Lua state. With address randomisation (ASLR) off, two runs in the
same second would get the same seed. The second run therefore gets a
padding variable in its environment: the environment sits at the top of
the stack, so its size moves every stack address and the seed differs
even then (the same fixed pair of seeds on every run; with ASLR on,
every run draws fresh ones).
]]

local D = {}

function D.parse(output)
  local d = nil
  for line in output:gmatch("[^\n]+") do
    local x = line:match("^digest: (%x+)$")
    if x then d = x end
  end
  return d
end

function D.compare(out1, out2)
  local d1, d2 = D.parse(out1), D.parse(out2)
  if not d1 or not d2 then
    return false, "no digest line (the test did not end with helper.done())"
  end
  if d1 ~= d2 then
    return false, "output differs between processes (digest " .. d1:sub(1, 12) .. "… vs "
      .. d2:sub(1, 12) .. "…): it depends on Lua's per-process table order"
  end
  return true
end

D.PAD = "KIT_DIGEST_PAD=" .. string.rep("x", 200) .. " "

function D.second(cmd)
  return D.PAD .. cmd
end

function D.runTwice(cmd)
  local outs, oks = {}, {}
  for i = 1, 2 do
    local h = io.popen((i == 1 and cmd or D.second(cmd)) .. " 2>&1")
    outs[i] = h:read("a")
    oks[i] = h:close()
  end
  local ok, msg = D.compare(outs[1], outs[2])
  if ok and not oks[2] then ok, msg = false, "the second process failed" end
  return ok, msg, outs[1], (oks[1] and oks[2]) and true or false
end

return D
