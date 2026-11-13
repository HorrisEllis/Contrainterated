--[[
harness — the test harness every Lua suite reports through.

Port of core 11.1 `tests/runner.js` (via jää 2.14.4 sisoTests/harness.lua,
unchanged in behaviour). Shared platform: no phase writes it.

  local H = require("harness")
  H.test(name, fn)  H.assert(c, msg)  H.assertEqual(a, e, label)
  H.assertThrows(fn, substring, label)  H.fail(msg)  H.finish(suite)

Final line: "{suite}: {passed}/{total} passed"
        or  "{suite}: {passed}/{total} passed, {failed} FAILED"
Assertion failures are raised as tables { message = … } so the
report shows the message without a file:line prefix; any other Lua
error is reported with its own message (a table error with a string
`message` field, such as a rejection, shows that message).
]]

local H = {}

local passed = 0
local failed = 0
local failures = {}

local function fail(message)
  error({ message = message }, 0)
end

local function messageOf(err)
  if type(err) == "table" and err.message ~= nil then
    return tostring(err.message)
  end
  return tostring(err)
end

-- JSON-ish rendering for failure messages (the JS runner uses
-- JSON.stringify). Only for messages; never for comparison.
local function show(v)
  local t = type(v)
  if v == nil then return "nil" end
  if t == "string" then return string.format("%q", v) end
  if t == "number" then
    if math.type(v) == "float" then return string.format("%.17g", v) .. "(float)" end
    return tostring(v)
  end
  return tostring(v)
end
H.show = show

function H.test(name, fn)
  local ok, err = pcall(fn)
  if ok then
    passed = passed + 1
  else
    failed = failed + 1
    local message = messageOf(err)
    failures[#failures + 1] = { name = name, message = message }
    print("  ✗  " .. name)
    print("     " .. message)
  end
end

function H.assert(condition, message)
  if not condition then fail(message or "assertion failed") end
end

-- Strict equality: same value and, for numbers, same subtype
-- (1 and 1.0 differ, as JSON.stringify shows them differently).
function H.assertEqual(actual, expected, label)
  local same = actual == expected
  if same and type(actual) == "number" and math.type(actual) ~= math.type(expected) then
    same = false
  end
  if not same then
    local prefix = label and (label .. ": ") or ""
    fail(prefix .. "expected " .. show(expected) .. ", got " .. show(actual))
  end
end

function H.assertClose(actual, expected, epsilon, label)
  epsilon = epsilon or 1e-10
  if math.abs(actual - expected) > epsilon then
    local prefix = label and (label .. ": ") or ""
    fail(prefix .. "expected ≈" .. show(expected) .. ", got " .. show(actual))
  end
end

function H.assertThrows(fn, substring, label)
  local prefix = label and (label .. ": ") or ""
  local ok, err = pcall(fn)
  if ok then
    fail(prefix .. "expected error, none thrown")
  end
  local message = messageOf(err)
  if substring and substring ~= "" and not string.find(message, substring, 1, true) then
    fail(prefix .. "expected error containing '" .. substring .. "', got '" .. message .. "'")
  end
end

function H.fail(message)
  fail(message)
end

function H.counts()
  return passed, failed
end

function H.report(suite)
  local total = passed + failed
  print()
  if failed == 0 then
    print(suite .. ": " .. passed .. "/" .. total .. " passed")
  else
    -- the failures first, so the summary is always the last line
    -- (gate-tests.md); runner.js prints them after it
    for _, f in ipairs(failures) do
      print("  FAIL: " .. f.name .. " — " .. f.message)
    end
    print(suite .. ": " .. passed .. "/" .. total .. " passed, " .. failed .. " FAILED")
  end
  return failed
end

-- report() and exit with the JS runner's code (0 on pass).
function H.finish(suite)
  local n = H.report(suite)
  os.exit(n == 0 and 0 or 1)
end

function H.reset()
  passed = 0
  failed = 0
  failures = {}
end

return H
