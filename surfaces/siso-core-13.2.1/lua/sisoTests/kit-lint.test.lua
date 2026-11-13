-- kit-lint — code/kit/lint.lua against the fixtures in
-- sisoTests/kit-fixtures/lint/ and their own config: each rule fires on
-- its fixture, the clean fixture passes, exceptions excuse and go stale,
-- the command reports and exits as kit-api says, and this project's
-- lint_config states the project's layer map.
--   lua5.4 sisoTests/kit-lint.test.lua
local root = (arg and arg[0] or ""):match("^(.-)sisoTests/") or ""
package.path = root .. "code/?.lua;" .. root .. "sisoTests/?.lua;" .. package.path

local H = require("harness")
local test, assert, assertEqual = H.test, H.assert, H.assertEqual
local L = require("kit.lint")

local fx = root .. "sisoTests/kit-fixtures/lint/"
local CFG = dofile(fx .. "lint_config.lua")

-- Lint a fixture as if it lived at `logical` in the fixture project.
local function lint(fixture, logical, cfg)
  return L.lintFile(cfg or CFG, logical or ("code/gates/" .. fixture), fx .. fixture)
end

local function rules(findings)
  local out = {}
  for _, f in ipairs(findings) do out[#out + 1] = f.rule .. ":" .. tostring(f.name) end
  table.sort(out)
  return table.concat(out, ",")
end

local function copy(t)
  if type(t) ~= "table" then return t end
  local c = {}
  for k, v in pairs(t) do c[k] = copy(v) end
  return c
end

---------------------------------------------------------------- the rules

test("clean pure module: no findings", function()
  assertEqual(rules(lint("clean.lua")), "")
end)

test("GLOBAL_WRITE: assigning a global", function()
  local r = rules(lint("global_write.lua"))
  assertEqual(r, "GLOBAL_READ:counter,GLOBAL_WRITE:counter")
end)

test("UPVALUE_WRITE: assigning a captured local", function()
  assertEqual(rules(lint("upvalue_write.lua")), "UPVALUE_WRITE:calls")
end)

test("UPVALUE_TABLE_WRITE: a field written on a captured table", function()
  assertEqual(rules(lint("upvalue_table_write.lua")), "UPVALUE_TABLE_WRITE:cache.last")
end)

test("SHARED_TABLE_WRITE: writes to shared tables through registers", function()
  assertEqual(rules(lint("shared_write.lua")),
    "SHARED_TABLE_WRITE:_ENV.string,SHARED_TABLE_WRITE:counter (setmetatable),SHARED_TABLE_WRITE:counter.n,"
    .. "SHARED_TABLE_WRITE:memo,SHARED_TABLE_WRITE:memo,SHARED_TABLE_WRITE:memo (table.insert),"
    .. "SHARED_TABLE_WRITE:rel.ORDER,SHARED_TABLE_WRITE:require(…),SHARED_TABLE_WRITE:slots,"
    .. "SHARED_TABLE_WRITE:slots (table.sort)")
end)

test("GLOBAL_READ: os is not allowed in a pure layer", function()
  local f = lint("os_read.lua")
  assertEqual(rules(f), "GLOBAL_READ:os")
  assertEqual(f[1].line, 2, "line")
  assertEqual(f[1].file, "code/gates/os_read.lua", "file")
end)

test("GLOBAL_READ: pairs only where the config's pairs_next says", function()
  assertEqual(rules(lint("pairs_read.lua")), "GLOBAL_READ:pairs")
  assertEqual(rules(lint("pairs_read.lua", "code/lib/x.lua")), "GLOBAL_READ:pairs", "another lib file")
  assertEqual(rules(lint("pairs_read.lua", "code/lib/util.lua")), "", "the listed file")
  local dir = copy(CFG)
  dir.pairs_next = { "code/lib/" }
  assertEqual(rules(lint("pairs_read.lua", "code/lib/x.lua", dir)), "", "a listed directory")
  assertEqual(rules(lint("pairs_read.lua", "code/gates/x.lua", dir)), "GLOBAL_READ:pairs", "outside it")
end)

test("RANDOM: math.random in a pure layer", function()
  assertEqual(rules(lint("random.lua")), "RANDOM:math.random")
end)

test("LAYER: requires the config's layer does not admit", function()
  assertEqual(rules(lint("layer.lua")), "LAYER:runner")
  assertEqual(rules(lint("layer.lua", "code/io/x.lua")), "LAYER:runner", "io may not either")
  assertEqual(rules(lint("layer.lua", "code/runner.lua")), "LAYER:runner", "nor the runner itself")
  local f = lint("layer.lua")
  assertEqual(f[1].detail, "gates may not require runner", "detail")
end)

test("LAYER: exact module entries admit only that module (base.event, not base.stream)", function()
  assertEqual(rules(lint("base_stream.lua")), "LAYER:base.stream,LAYER:base.streamlog")
  assertEqual(rules(lint("base_stream.lua", "code/lib/x.lua")), "", "lib admits base.*")
  assertEqual(rules(lint("base_stream.lua", "code/base/x.lua")), "", "base admits itself")
  assertEqual(rules(lint("base_stream.lua", "code/io/x.lua")),
    "LAYER:base.event,LAYER:base.stream,LAYER:base.streamlog", "io admits no base")
end)

test("EXTERNAL_REQUIRE: a module in no layer", function()
  assertEqual(rules(lint("external.lua")), "EXTERNAL_REQUIRE:lfs")
  assertEqual(rules(lint("external.lua", "code/io/x.lua")), "EXTERNAL_REQUIRE:lfs", "every layer")
end)

test("DYNAMIC_REQUIRE: the argument must be a string literal", function()
  assertEqual(rules(lint("dynamic.lua")), "DYNAMIC_REQUIRE:,DYNAMIC_REQUIRE:")
  assertEqual(rules(lint("dynamic.lua", "code/runner.lua")), "DYNAMIC_REQUIRE:,DYNAMIC_REQUIRE:", "every layer")
end)

test("comments and strings are not code", function()
  assertEqual(rules(lint("comment_only.lua")), "")
end)

test("SYNTAX: a file that does not compile", function()
  assertEqual(rules(lint("syntax.lua")), "SYNTAX:")
end)

test("UNKNOWN_LAYER and UNREADABLE", function()
  assertEqual(rules(lint("clean.lua", "code/misc/x.lua")), "UNKNOWN_LAYER:code/misc/x.lua")
  assertEqual(rules(L.lintFile(CFG, "code/gates/none.lua", fx .. "none.lua")), "UNREADABLE:" .. fx .. "none.lua")
end)

test("layers outside the pure list get the layer rules only", function()
  assertEqual(rules(lint("os_read.lua", "code/io/x.lua")), "")
  assertEqual(rules(lint("upvalue_write.lua", "code/runner.lua")), "")
  assertEqual(rules(lint("shared_write.lua", "code/io/x.lua")), "", "io admits lib.*")
  assertEqual(rules(lint("random.lua", "code/io/x.lua")), "")
  local cfg = copy(CFG)
  cfg.pure = { "io" }
  assertEqual(rules(lint("os_read.lua", "code/io/x.lua", cfg)), "GLOBAL_READ:os", "pure is the config's")
  assertEqual(rules(lint("os_read.lua", "code/gates/x.lua", cfg)), "", "and only the config's")
end)

---------------------------------------------------------------- the map

test("layerOfFile / layerOfModule: directories, single files, the longest path", function()
  assertEqual(L.layerOfFile(CFG, "code/gates/a/b.lua"), "gates")
  assertEqual(L.layerOfFile(CFG, "code/runner.lua"), "runner")
  assertEqual(L.layerOfFile(CFG, "code/runner2.lua"), nil)
  assertEqual(L.layerOfModule(CFG, "lib.util"), "lib")
  assertEqual(L.layerOfModule(CFG, "runner"), "runner")
  assertEqual(L.layerOfModule(CFG, "lfs"), nil)
  local cfg = copy(CFG)
  cfg.layers.inner = { path = "code/gates/inner/", requires = {} }
  assertEqual(L.layerOfFile(cfg, "code/gates/inner/x.lua"), "inner")
  assertEqual(L.layerOfFile(cfg, "code/gates/x.lua"), "gates")
end)

test("checkConfig: a usable config passes; a broken one is named", function()
  assertEqual(#L.checkConfig(CFG), 0, table.concat(L.checkConfig(CFG), "; "))
  local bad = copy(CFG)
  bad.pure = { "base", "nowhere" }
  bad.exceptions = { { file = "code/x.lua", rule = "NOPE", name = "y", reason = "" } }
  bad.layers.io.requires = nil
  local p = table.concat(L.checkConfig(bad), "; ")
  assert(p:find("pure layer nowhere is not a layer", 1, true), p)
  assert(p:find("exception 1 names no rule: NOPE", 1, true), p)
  assert(p:find("exception 1 has no reason", 1, true), p)
  assert(p:find("layer io has no requires list", 1, true), p)
  assertEqual(L.checkConfig(nil)[1], "the config is not a table")
end)

---------------------------------------------------------------- exceptions

test("exceptions: a matching finding is excused; an unused one is stale", function()
  local findings = lint("upvalue_write.lua", "code/base/x.lua")
  local ex = {
    { file = "code/base/x.lua", rule = "UPVALUE_WRITE", name = "calls", reason = "fixture" },
    { file = "code/base/x.lua", rule = "GLOBAL_READ", name = "os", reason = "fixture" },
    { file = "code/base/gone.lua", rule = "GLOBAL_READ", name = "os", reason = "fixture" },
  }
  local bad, excused, stale = L.applyExceptions(findings, ex, { "code/base/x.lua" })
  assertEqual(#bad, 0)
  assertEqual(#excused, 1)
  assertEqual(excused[1].name, "calls")
  assertEqual(#stale, 1, "a file not linted is not stale")
  assertEqual(stale[1].name, "os")
  assertEqual(stale[1].file, "code/base/x.lua")
end)

test("exceptions: file, rule and name must all match", function()
  local findings = lint("upvalue_write.lua", "code/base/x.lua")
  local ex = { { file = "code/base/x.lua", rule = "UPVALUE_WRITE", name = "other", reason = "fixture" } }
  local bad, excused, stale = L.applyExceptions(findings, ex, { "code/base/x.lua" })
  assertEqual(#bad, 1)
  assertEqual(#excused, 0)
  assertEqual(#stale, 1)
end)

---------------------------------------------------------------- the command

local function sh(s) return "'" .. s:gsub("'", "'\\''") .. "'" end

local function absolute(p)
  if p:sub(1, 1) == "/" then return p end
  local h = io.popen("pwd")
  local cwd = h:read("l")
  h:close()
  return cwd .. "/" .. p
end

test("command: the fixture project's report, final line and exit code", function()
  local lintPath = absolute(root .. "code/kit/lint.lua")
  local h = io.popen("cd " .. sh(fx .. "project") .. " && LUA_PATH='../?.lua;;' lua5.4 "
    .. sh(lintPath) .. " 2>&1; echo \"exit=$?\"")
  local out = h:read("a")
  h:close()
  local lines = {}
  for l in out:gmatch("[^\n]*") do lines[#lines + 1] = l end
  while lines[#lines] == "" do lines[#lines] = nil end
  assertEqual(lines[#lines], "exit=1", out)
  assertEqual(lines[#lines - 1], "lint: 3 files, 1 findings, 1 excused, 1 stale exceptions", out)
  assert(out:find("FINDING  code/lib/clock.lua:2: GLOBAL_READ os", 1, true), out)
  assert(out:find("excused  code/gates/counter.lua:3: UPVALUE_WRITE calls — fixture: excused", 1, true), out)
  assert(out:find("STALE    code/gates/ok.lua: GLOBAL_READ os (exception matches nothing)", 1, true), out)
  assert(not out:find("gone.lua", 1, true), "an exception for a missing file is not stale")
end)

test("run: a clean project exits 0 (N = 0 and S = 0)", function()
  local cfg = copy(CFG)
  cfg.exceptions = {
    { file = "code/gates/counter.lua", rule = "UPVALUE_WRITE", name = "calls", reason = "fixture" },
    { file = "code/lib/clock.lua", rule = "GLOBAL_READ", name = "os", reason = "fixture" },
  }
  local printed = {}
  local files, bad, excused, stale = L.run(cfg, fx .. "project/", function(s) printed[#printed + 1] = s end)
  assertEqual(#files, 3)
  assertEqual(#bad, 0)
  assertEqual(#excused, 2)
  assertEqual(#stale, 0)
  assertEqual(printed[#printed], "lint: 3 files, 0 findings, 2 excused, 0 stale exceptions")
end)

test("run: a broken config is a finding, and nothing is linted", function()
  local cfg = copy(CFG)
  cfg.pure = { "nowhere" }
  local printed = {}
  local files, bad = L.run(cfg, fx .. "project/", function(s) printed[#printed + 1] = s end)
  assertEqual(#files, 0)
  assertEqual(#bad, 1)
  assertEqual(bad[1].rule, "CONFIG")
  assertEqual(printed[#printed], "lint: 0 files, 1 findings, 0 excused, 0 stale exceptions")
end)

---------------------------------------------------------------- this project

test("this project's lint_config: shape and layer map", function()
  local P = require("lint_config")
  assertEqual(#L.checkConfig(P), 0, table.concat(L.checkConfig(P), "; "))
  local function may(layer, mod) return L.mayRequire(P, layer, mod) end
  assertEqual(table.concat(P.pure, ","), "core", "the observer keeps state by design (R2): layer rules only")
  assert(may("core", "core.stream") and not may("core", "observer.streamlog") and not may("core", "kit.data"), "core")
  assert(not may("observer", "core.event") and not may("observer", "kit.data"), "observer takes nothing from core or kit")
  assert(not may("contracts", "core.event") and not may("contracts", "contracts.core_api"), "contracts require nothing")
  assert(may("kit", "kit.data") and may("kit", "core.event") and may("kit", "core.gate")
    and may("kit", "contracts.kit_api") and may("kit", "harness"), "kit admits (the helper reports through the shared harness)")
  assert(not may("kit", "core.stream") and not may("kit", "observer.streamlog"), "kit refuses")
  assert(may("fixtures", "core.stream") and may("fixtures", "observer.x") and may("fixtures", "contracts.x")
    and may("fixtures", "kit.helper") and may("fixtures", "harness"), "fixtures admit")
  assertEqual(L.layerOfModule(P, "harness"), "harness")
  assertEqual(L.layerOfFile(P, "code/harness.lua"), "harness")
  assert(L.pairsAllowed(P, "code/core/x.lua") and not L.pairsAllowed(P, "code/observer/x.lua"), "pairs_next")
  assertEqual(#P.exceptions, 0, "no exceptions: the observer's clock is outside the purity rules now")
end)

H.finish("kit-lint")
