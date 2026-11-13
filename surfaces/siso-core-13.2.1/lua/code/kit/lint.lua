--[[
kit.lint — purity and layering lint for a Lua SISO project (contract
kit-api). Generalised from jää 2.14.4 (sisoTests/tools/lint.lua,
phased-spec §5.3): everything project-specific lives in the project's
config module `lint_config`, found on package.path.

  lua5.4 code/kit/lint.lua      (run from the project root)
  Final line "lint: F files, N findings, X excused, S stale exceptions";
  exit 0 only when N = 0 and S = 0.

  local L = require("kit.lint")  -- the same file, as a module

THE CONFIG (module `lint_config`, a plain table)

  source      the import root, default "code/": every .lua file under it
              is linted, and module "a.b" is the file source.."a/b.lua"
  layers      name → { path = "code/core/" (a directory, ending in "/")
                              or "code/harness.lua" (one file),
                       requires = { "core.*", "core.event", "harness", … } }
              "x.*" admits every module under x; any other entry admits
              exactly that module. A file belongs to the layer with the
              longest matching path; a module to the layer its file
              would belong to.
  pure        list of layer names that get the purity rules
  pairs_next  paths (directories ending in "/", or files) of the pure
              layers where `pairs` and `next` may be read
  exceptions  list of { file, rule, name, reason }: a finding matching
              file, rule and name is excused; an exception for a linted
              file that matches nothing is stale

RULES

Pure layers get every rule (read from `luac5.4 -p -l -l` listings and
the source text):
  GLOBAL_WRITE         SETTABUP on _ENV            (a global assigned)
  UPVALUE_TABLE_WRITE  SETTABUP on another upvalue (a field written on a
                       table captured from an enclosing scope)
  UPVALUE_WRITE        SETUPVAL                    (an upvalue assigned)
  SHARED_TABLE_WRITE   inside a function, SETFIELD / SETTABLE / SETI /
                       SETLIST on a register holding a table reached from
                       an upvalue or a global (a module table, a captured
                       table, a standard library table, a `require`d
                       module), directly or through field reads and
                       moves; and table.insert / remove / sort / move or
                       setmetatable called on such a table. The main chunk
                       is not tracked: it builds the module.
  GLOBAL_READ          GETTABUP on _ENV outside ALLOWED_GLOBALS; `pairs`
                       and `next` only under the config's pairs_next
  RANDOM               math.random / math.randomseed in the code
Every layer gets:
  LAYER                a require of a project module the layer's
                       `requires` does not admit
  EXTERNAL_REQUIRE     a require of a module that is in no layer
  DYNAMIC_REQUIRE      a require whose argument is not a string literal,
                       or `require` used as a value (read from the
                       bytecode: a GETTABUP of _ENV "require" whose
                       register is not the callee of a CALL / TAILCALL
                       before anything else claims it; `pcall(require,
                       name)`, `local r = require`, `f(require)`,
                       `{ require }`), detail "require used as a value"
And every file: SYNTAX (luac rejects it), UNKNOWN_LAYER (under source but
in no layer), UNREADABLE.

Advisory, with known blind spots: the register tracking is linear (it
ignores jumps), a standard-library function reached through a local
alias, writes through tables passed in as arguments or returned by
calls, a table's address reaching output through `tostring`, and
`require` reached through another table (`_G.require`, `rawget(_ENV,
"require")`). A clean lint is not proof; conformance cites the tests.
]]

local L = {}

L.ALLOWED_GLOBALS = {
  require = true, ipairs = true, select = true, type = true,
  tostring = true, tonumber = true, error = true, pcall = true,
  setmetatable = true, getmetatable = true, rawequal = true,
  rawget = true, rawlen = true, math = true, string = true,
  table = true, utf8 = true,
}

L.PAIRS_NEXT = { pairs = true, next = true }

L.RULES = {
  GLOBAL_WRITE = true, UPVALUE_TABLE_WRITE = true, UPVALUE_WRITE = true,
  SHARED_TABLE_WRITE = true, GLOBAL_READ = true, RANDOM = true, LAYER = true,
  EXTERNAL_REQUIRE = true, DYNAMIC_REQUIRE = true, SYNTAX = true,
  UNKNOWN_LAYER = true, UNREADABLE = true,
}

-- Opcodes that do not write register A (everything else does).
local NO_WRITE = {
  SETTABUP = true, SETUPVAL = true, SETTABLE = true, SETI = true, SETFIELD = true,
  SETLIST = true, JMP = true, EQ = true, LT = true, LE = true, EQK = true, EQI = true,
  LTI = true, LEI = true, GTI = true, GEI = true, TEST = true, RETURN = true,
  RETURN0 = true, RETURN1 = true, CLOSE = true, TBC = true, MMBIN = true,
  MMBINI = true, MMBINK = true, EXTRAARG = true, VARARGPREP = true,
}
local TABLE_WRITE = { SETFIELD = true, SETTABLE = true, SETI = true, SETLIST = true }
-- standard-library calls that write into a table argument: callee → the
-- argument positions written
local WRITING_CALLS = {
  ["_ENV.table.insert"] = { 1 }, ["_ENV.table.remove"] = { 1 }, ["_ENV.table.sort"] = { 1 },
  ["_ENV.table.move"] = { 1, 5 }, ["_ENV.setmetatable"] = { 1 },
}

---------------------------------------------------------------- config

local function source(cfg) return cfg.source or "code/" end

local function sortedKeys(t)
  local ks = {}
  for k in pairs(t) do ks[#ks + 1] = k end
  table.sort(ks)
  return ks
end

local function pathMatches(entry, path)
  if entry:sub(-1) == "/" then return path:sub(1, #entry) == entry end
  return path == entry
end

-- The layer a project path belongs to (the longest matching path), or nil.
function L.layerOfFile(cfg, path)
  local best, len = nil, -1
  for _, name in ipairs(sortedKeys(cfg.layers)) do
    local p = cfg.layers[name].path
    if pathMatches(p, path) and #p > len then best, len = name, #p end
  end
  return best
end

-- The layer a module belongs to ("core.event" → the layer of
-- code/core/event.lua or code/core/event/init.lua), or nil.
function L.layerOfModule(cfg, mod)
  local base = source(cfg) .. mod:gsub("%.", "/")
  return L.layerOfFile(cfg, base .. ".lua") or L.layerOfFile(cfg, base .. "/init.lua")
end

-- Whether `layer`'s requires admit module `mod`.
function L.mayRequire(cfg, layer, mod)
  local spec = cfg.layers[layer]
  for _, e in ipairs(spec and spec.requires or {}) do
    if e == mod then return true end
    local prefix = e:match("^(.*)%.%*$")
    if prefix and mod:sub(1, #prefix + 1) == prefix .. "." then return true end
  end
  return false
end

function L.isPure(cfg, layer)
  for _, name in ipairs(cfg.pure or {}) do if name == layer then return true end end
  return false
end

function L.pairsAllowed(cfg, path)
  for _, e in ipairs(cfg.pairs_next or {}) do if pathMatches(e, path) then return true end end
  return false
end

-- Problems with a config's shape, as strings (empty when it is usable).
function L.checkConfig(cfg)
  local p = {}
  local function fail(s) p[#p + 1] = s end
  if type(cfg) ~= "table" then return { "the config is not a table" } end
  if cfg.source ~= nil and (type(cfg.source) ~= "string" or cfg.source:sub(-1) ~= "/") then
    fail("source must be a directory ending in /")
  end
  if type(cfg.layers) ~= "table" or next(cfg.layers) == nil then
    fail("layers must name at least one layer")
    return p
  end
  local seen = {}
  for _, name in ipairs(sortedKeys(cfg.layers)) do
    local l = cfg.layers[name]
    if type(l) ~= "table" or type(l.path) ~= "string" or l.path == "" then
      fail("layer " .. tostring(name) .. " has no path")
    elseif type(l.requires) ~= "table" then
      fail("layer " .. name .. " has no requires list")
    else
      if seen[l.path] then fail("layers " .. seen[l.path] .. " and " .. name .. " share a path") end
      seen[l.path] = name
      for _, e in ipairs(l.requires) do
        if type(e) ~= "string" or e == "" then fail("layer " .. name .. " has a requires entry that is not a module") end
      end
    end
  end
  for _, name in ipairs(cfg.pure or {}) do
    if not cfg.layers[name] then fail("pure layer " .. tostring(name) .. " is not a layer") end
  end
  for _, e in ipairs(cfg.pairs_next or {}) do
    if type(e) ~= "string" or e == "" then fail("pairs_next has an entry that is not a path") end
  end
  for i, x in ipairs(cfg.exceptions or {}) do
    local at = "exception " .. i
    if type(x) ~= "table" then fail(at .. " is not a table")
    else
      if type(x.file) ~= "string" then fail(at .. " has no file") end
      if not L.RULES[x.rule] then fail(at .. " names no rule: " .. tostring(x.rule)) end
      if type(x.name) ~= "string" then fail(at .. " has no name (\"\" for none)") end
      if type(x.reason) ~= "string" or not x.reason:match("%S") then fail(at .. " has no reason") end
    end
  end
  return p
end

-- Load the config module by name from package.path (default
-- "lint_config"). Returns cfg, or nil and why.
function L.loadConfig(name)
  name = name or "lint_config"
  local path, why = package.searchpath(name, package.path)
  if not path then return nil, "config module " .. name .. " not found:" .. why end
  local chunk, err = loadfile(path)
  if not chunk then return nil, err end
  local ok, cfg = pcall(chunk, name, path)
  if not ok then return nil, tostring(cfg) end
  return cfg, path
end

---------------------------------------------------------------- reading

local function readFile(path)
  local f = io.open(path, "rb")
  if not f then return nil end
  local s = f:read("a")
  f:close()
  return s
end

local function lineOf(src, pos)
  local n = 1
  for _ in src:sub(1, pos):gmatch("\n") do n = n + 1 end
  return n
end

--[[
L.strip(src) → code, bare
`code` is the source with comments blanked; `bare` also blanks the
contents of every string (keeping its delimiters). Newlines are kept and
lengths are equal, so a position in one is the same in the other and in
the source.
]]
function L.strip(src)
  local code, bare, i, n = {}, {}, 1, #src
  local function blank(s) return (s:gsub("[^\n]", " ")) end
  local function both(s) code[#code + 1] = s; bare[#bare + 1] = s end
  while i <= n do
    local c = src:sub(i, i)
    if c == "-" and src:sub(i + 1, i + 1) == "-" then
      local eqs = src:match("^%[(=*)%[", i + 2)
      local j
      if eqs then
        local _, e = src:find("%]" .. eqs .. "%]", i + 2)
        j = e or n
      else
        j = (src:find("\n", i + 2, true) or (n + 1)) - 1
      end
      both(blank(src:sub(i, j)))
      i = j + 1
    elseif c == '"' or c == "'" then
      local j = i + 1
      while j <= n do
        local d = src:sub(j, j)
        if d == "\\" then j = j + 2
        elseif d == c or d == "\n" then break
        else j = j + 1 end
      end
      if j > n then j = n end
      local s = src:sub(i, j)
      code[#code + 1] = s
      bare[#bare + 1] = c .. blank(s:sub(2, -2)) .. (#s > 1 and s:sub(-1) or "")
      i = j + 1
    elseif c == "[" and src:match("^%[=*%[", i) then
      local open = src:match("^%[=*%[", i)
      local _, e = src:find("%]" .. open:sub(2, -2) .. "%]", i)
      e = e or n
      local s = src:sub(i, e)
      code[#code + 1] = s
      bare[#bare + 1] = open .. blank(s:sub(#open + 1, -#open - 1)) .. s:sub(-#open)
      i = e + 1
    else
      both(c)
      i = i + 1
    end
  end
  return table.concat(code), table.concat(bare)
end

---------------------------------------------------------------- rules

--[[
`require` used as a value, in every function of a listing, main chunk
included. Arguments are evaluated into registers above the callee and
nothing in an argument writes at or below it, so a `require` read into
register R is a call exactly when the first instruction after it that
writes a register at or below R is CALL / TAILCALL with A = R. Anything
else claiming R first (a CALL below R that takes it as an argument, a
MOVE into a local, the function ending with R still live) means the
value went somewhere the lint cannot read.
]]
local function requireValueFindings(logical, listing, out)
  local pending = {}  -- { reg, line } for each require read not yet called
  local function flush()
    for _, q in ipairs(pending) do
      out[#out + 1] = { file = logical, line = q.line, rule = "DYNAMIC_REQUIRE", name = "",
        detail = "require used as a value" }
    end
    pending = {}
  end
  for line in listing:gmatch("[^\n]+") do
    if line:match("^main <") or line:match("^function <") then flush() end
    local ln, op, args = line:match("^%s*%d+%s+%[(%d+)%]%s+(%u+)%s+([^;]*)")
    local A = args and tonumber(args:match("^%s*(%-?%d+)"))
    if op and A and not NO_WRITE[op] then
      local keep = {}
      for _, q in ipairs(pending) do
        if q.reg < A then keep[#keep + 1] = q
        elseif not ((op == "CALL" or op == "TAILCALL") and q.reg == A) then
          out[#out + 1] = { file = logical, line = q.line, rule = "DYNAMIC_REQUIRE", name = "",
            detail = "require used as a value" }
        end
      end
      pending = keep
      if op == "GETTABUP" and line:match(';%s*_ENV%s+"require"%s*$') then
        pending[#pending + 1] = { reg = A, line = tonumber(ln) }
      end
    end
  end
  flush()
end

local function bytecodeFindings(cfg, logical, real, layer, luac, out)
  local p = io.popen(luac .. " -p -l -l '" .. real:gsub("'", "'\\''") .. "' 2>&1")
  local listing = p:read("a")
  local ok = p:close()
  if not ok then
    out[#out + 1] = { file = logical, line = 0, rule = "SYNTAX", name = "", detail = (listing:gsub("%s+$", "")) }
    return
  end
  requireValueFindings(logical, listing, out)
  if not L.isPure(cfg, layer) then return end
  local mayPairs = L.pairsAllowed(cfg, logical)
  -- register tracking, per function (the main chunk is skipped)
  local inMain, taint, isReq = true, {}, {}
  for line in listing:gmatch("[^\n]+") do
    if line:match("^main <") then inMain, taint, isReq = true, {}, {} end
    if line:match("^function <") then inMain, taint, isReq = false, {}, {} end
    local tln, top, targs = line:match("^%s*%d+%s+%[(%d+)%]%s+(%u+)%s+([^;]*)")
    if tln and not inMain then
      local nums = {}
      for n in targs:gmatch("%-?%d+") do nums[#nums + 1] = tonumber(n) end
      local A, B = nums[1], nums[2]
      local tcomment = line:match(";%s*(.*)$") or ""
      if TABLE_WRITE[top] and A and taint[A] then
        out[#out + 1] = { file = logical, line = tonumber(tln), rule = "SHARED_TABLE_WRITE", name = taint[A] }
      end
      if top == "GETUPVAL" then
        taint[A] = tcomment:match("^(%S+)") or "?"
        isReq[A] = nil
      elseif top == "GETTABUP" then
        local up, key = tcomment:match('^(%S+)%s+"([^"]*)"')
        taint[A] = (up or "?") .. "." .. (key or "?")
        isReq[A] = (up == "_ENV" and key == "require") or nil
      elseif top == "GETFIELD" or top == "GETTABLE" or top == "GETI" then
        local k = tcomment:match('^"([^"]*)"')
        taint[A] = taint[B] and (taint[B] .. (k and ("." .. k) or "[]")) or nil
        isReq[A] = nil
      elseif top == "SELF" then
        taint[A + 1] = taint[B]
        taint[A] = taint[B]
        isReq[A], isReq[A + 1] = nil, nil
      elseif top == "MOVE" then
        taint[A], isReq[A] = taint[B], isReq[B]
      elseif top == "CALL" or top == "TAILCALL" then
        local writes = WRITING_CALLS[taint[A] or ""]
        if writes then
          for _, k in ipairs(writes) do
            local a = taint[A + k]
            if a and (B == 0 or k < B) then
              out[#out + 1] = { file = logical, line = tonumber(tln), rule = "SHARED_TABLE_WRITE",
                name = a .. " (" .. taint[A]:gsub("^_ENV%.", "") .. ")" }
            end
          end
        end
        local req = isReq[A]
        for r = A, A + 255 do taint[r], isReq[r] = nil, nil end
        if req then taint[A] = "require(…)" end
      elseif top == "LOADNIL" then
        for r = A, A + (B or 0) do taint[r], isReq[r] = nil, nil end
      elseif A and not NO_WRITE[top] then
        taint[A], isReq[A] = nil, nil
      end
    end
    local ln, op, comment = line:match("^%s*%d+%s+%[(%d+)%]%s+(%u+)%s.-;%s*(.*)$")
    if op == "SETTABUP" or op == "GETTABUP" then
      local up, key = comment:match('^(%S+)%s+"([^"]*)"')
      if up == "_ENV" and op == "SETTABUP" then
        out[#out + 1] = { file = logical, line = tonumber(ln), rule = "GLOBAL_WRITE", name = key }
      elseif up and op == "SETTABUP" then
        out[#out + 1] = { file = logical, line = tonumber(ln), rule = "UPVALUE_TABLE_WRITE", name = up .. "." .. key }
      elseif up == "_ENV" and op == "GETTABUP" then
        if not (L.ALLOWED_GLOBALS[key] or (L.PAIRS_NEXT[key] and mayPairs)) then
          out[#out + 1] = { file = logical, line = tonumber(ln), rule = "GLOBAL_READ", name = key }
        end
      end
    elseif op == "SETUPVAL" then
      out[#out + 1] = { file = logical, line = tonumber(ln), rule = "UPVALUE_WRITE", name = comment:match("^(%S+)") or "?" }
    end
  end
end

local function sourceFindings(cfg, logical, src, layer, out)
  local code, bare = L.strip(src)
  if L.isPure(cfg, layer) then
    local pos = 1
    while true do
      local s, e, fn = bare:find("math%s*%.%s*(random%w*)", pos)
      if not s then break end
      out[#out + 1] = { file = logical, line = lineOf(bare, s), rule = "RANDOM", name = "math." .. fn }
      pos = e + 1
    end
  end
  local pos = 1
  while true do
    -- the frontier keeps "required" and "requires" from reading as a call
    local s, e = bare:find("require%f[^%w_]", pos)
    if not s then break end
    -- skip fields and identifiers that merely end in "require"
    local prev = s > 1 and bare:sub(s - 1, s - 1) or ""
    if not prev:match("[%w_%.:]") then
      local rest = code:sub(e + 1)
      -- a call is `require` followed by "(", a string or a table; anything
      -- else (`local require = require`, `{ require = true }`) is the name
      local isCall = rest:match("^%s*[%(\"'{]") or rest:match("^%s*%[=*%[")
      local _, mod = rest:match("^%s*%(%s*([\"'])([^\n]-)%1%s*%)")
      if not mod then _, mod = rest:match("^%s*([\"'])([^\n]-)%1") end
      local line = lineOf(bare, s)
      if not isCall then
        -- not a call
      elseif not mod then
        out[#out + 1] = { file = logical, line = line, rule = "DYNAMIC_REQUIRE", name = "" }
      else
        local target = L.layerOfModule(cfg, mod)
        if not target then
          out[#out + 1] = { file = logical, line = line, rule = "EXTERNAL_REQUIRE", name = mod }
        elseif not L.mayRequire(cfg, layer, mod) then
          out[#out + 1] = { file = logical, line = line, rule = "LAYER", name = mod,
            detail = layer .. " may not require " .. mod }
        end
      end
    end
    pos = e + 1
  end
end

--[[
L.lintFile(cfg, logical, real?, luac?) → findings
`logical` is the project path that decides the layer ("code/core/x.lua");
`real` is the file read (they differ only in the lint's own tests).
A finding is { file, line, rule, name, detail? }.
]]
function L.lintFile(cfg, logical, real, luac)
  luac = luac or "luac5.4"
  real = real or logical
  local layer = L.layerOfFile(cfg, logical)
  if not layer then
    return { { file = logical, line = 0, rule = "UNKNOWN_LAYER", name = logical } }
  end
  local src = readFile(real)
  if not src then
    return { { file = logical, line = 0, rule = "UNREADABLE", name = real } }
  end
  local out = {}
  bytecodeFindings(cfg, logical, real, layer, luac, out)
  sourceFindings(cfg, logical, src, layer, out)
  return out
end

-- Split findings into (unexcused, excused) and list stale exceptions:
-- those for a linted file that match nothing. An exception for a file
-- not linted (not there yet) is not stale. lintedFiles nil: all count.
function L.applyExceptions(findings, exceptions, lintedFiles)
  exceptions = exceptions or {}
  local used, bad, excused = {}, {}, {}
  for _, f in ipairs(findings) do
    local hit = nil
    for i, x in ipairs(exceptions) do
      if x.file == f.file and x.rule == f.rule and x.name == f.name then hit = i break end
    end
    if hit then
      used[hit] = true
      excused[#excused + 1] = f
    else
      bad[#bad + 1] = f
    end
  end
  local linted = {}
  for _, p in ipairs(lintedFiles or {}) do linted[p] = true end
  local stale = {}
  for i, x in ipairs(exceptions) do
    if not used[i] and (lintedFiles == nil or linted[x.file]) then stale[#stale + 1] = x end
  end
  return bad, excused, stale
end

-- Every .lua file under <root><source>, sorted, as project paths.
function L.projectFiles(cfg, root)
  root = (root and root ~= "") and root or "."
  local dir = source(cfg):gsub("/$", "")
  local p = io.popen("cd '" .. root:gsub("'", "'\\''") .. "' && find '" .. dir
    .. "' -name '*.lua' -type f 2>/dev/null | LC_ALL=C sort")
  local files = {}
  for line in p:lines() do files[#files + 1] = line end
  p:close()
  return files
end

function L.format(f)
  local s = f.file .. ":" .. tostring(f.line) .. ": " .. f.rule
  if f.name and f.name ~= "" then s = s .. " " .. f.name end
  if f.detail then s = s .. " (" .. f.detail .. ")" end
  return s
end

local function reasonFor(exceptions, f)
  for _, x in ipairs(exceptions) do
    if x.file == f.file and x.rule == f.rule and x.name == f.name then return x.reason end
  end
  return ""
end

--[[
L.run(cfg, root?, print?) → files, bad, excused, stale
Lints every project file under root ("" = the current directory), prints
the report and the final line through `print`, and returns the lists.
Config problems are findings (rule CONFIG) and are printed first.
]]
function L.run(cfg, root, emit)
  emit = emit or print
  root = root or ""
  local bad, excused, stale, files = {}, {}, {}, {}
  local problems = L.checkConfig(cfg)
  if #problems > 0 then
    for _, s in ipairs(problems) do bad[#bad + 1] = { file = "lint_config", line = 0, rule = "CONFIG", name = "", detail = s } end
  else
    files = L.projectFiles(cfg, root)
    local all = {}
    for _, path in ipairs(files) do
      for _, f in ipairs(L.lintFile(cfg, path, root .. path)) do all[#all + 1] = f end
    end
    bad, excused, stale = L.applyExceptions(all, cfg.exceptions, files)
  end
  for _, f in ipairs(bad) do emit("FINDING  " .. L.format(f)) end
  for _, f in ipairs(excused) do emit("excused  " .. L.format(f) .. " — " .. reasonFor(cfg.exceptions or {}, f)) end
  for _, x in ipairs(stale) do
    emit("STALE    " .. x.file .. ": " .. x.rule .. " " .. x.name .. " (exception matches nothing)")
  end
  emit("")
  emit("lint: " .. #files .. " files, " .. #bad .. " findings, " .. #excused .. " excused, "
    .. #stale .. " stale exceptions")
  return files, bad, excused, stale
end

---------------------------------------------------------------- command

-- Run as a command unless loaded by require. require passes the module
-- name and the file it loaded this chunk from, and that file is this
-- chunk's own source; command-line arguments, however many, are not.
local modName, loadedFrom = ...
local here = debug.getinfo(1, "S").source
local isModule = select("#", ...) == 2 and type(modName) == "string"
  and type(loadedFrom) == "string" and "@" .. loadedFrom == here
if not isModule then
  package.path = "code/?.lua;sisoTests/?.lua;" .. package.path
  local cfg, why = L.loadConfig("lint_config")
  if not cfg then
    print("FINDING  lint_config:0: CONFIG (" .. tostring(why) .. ")")
    print("")
    print("lint: 0 files, 1 findings, 0 excused, 0 stale exceptions")
    os.exit(1)
  end
  local _, bad, _, stale = L.run(cfg, "")
  os.exit((#bad == 0 and #stale == 0) and 0 or 1)
end

return L
