--[[
contract: kit-api — what the kit promises every Lua project that vendors
it (core 13): jää, the pipeline, spine, and this project's own fixtures
runner. Only a contracts phase changes this file.

Between: kit -> fixtures (and consumers outside this project).

MODULES (import root code/; consumers vendor code/core/, code/kit/ and
the shared harness code/harness.lua, which kit.helper reports through)

  kit.data   plain data and its canonical JSON (decision 5)
    data.null              the JSON null sentinel (a value, not nil)
    data.array(t) → t      marks t as a JSON array (an empty one too)
    data.is_array(t)       true for a table marked by data.array
    data.encode(v) → s | nil, why
                           canonical JSON: object keys sorted, integers
                           and floats kept apart (1 vs 1.0), no
                           functions, userdata, cycles or NaN
                           A frozen table (core-api READ-ONLY) is encoded
                           as the plain data it presents: its contents are
                           read through indexing, # and pairs, its mark
                           through getmetatable (which answers false for a
                           frozen table that had no metatable), so
                           encode(frozen v) == encode(v). Residue, samples
                           and observer records can be serialised as they
                           are handed out.
    data.decode(s) → v | nil, why
                           objects become tables, arrays become tables
                           marked by data.array, null becomes data.null
    The marks answer getmetatable with strings, never a table (core
    13.0.0; core-api STATELESS THROUGH THE EVENT): a marked array answers
    "kit.data.array", and a frozen copy answers the same, so the mark
    survives freezing. data.null answers "readonly:kit.data.null", an
    immutable sentinel (core-api READ-ONLY), so freezing passes it through
    and `v == data.null` still holds on frozen data. Nothing kit.data hands
    out gives a gate a table it could write and later read back.

  kit.sha256
    sha256.hex(s) → 64 lowercase hex characters

  kit.helper  the per-gate test API (phased-spec references/gate-tests.md)
    helper.gate(signature)       the shipped gate, from the project's
                                 register list (module `register`, or
                                 the one named by KIT_REGISTER)
    helper.event(type, data)     an Event
    helper.error(source, code, detail?)
                                 matches an error event:
                                 { type = "error", data = { source, code,
                                   message = <any string>, detail? } }
    helper.accept / abort / drop / guard (label, gate, event, expected)
                                 one case of each kind
    helper.check(kind, gate, event, expected) → ok, message
                                 the verdict without counting it
    helper.done(suite?)          prints the census lines, then the
                                 harness's final line, and exits
    Every case runs only the gate, compares the whole output, checks the
    gate's declared shape ({ emits, rejects }), that the input was not
    changed, that two runs agree and that the output encodes as
    canonical JSON; done() prints "gates:", "shape:", "register:" and
    "digest:" lines. "register: <module> <path>" names the register module
    the helper read gates from and the file it resolved to, so the census
    can check at run time that a per-gate test used the project's own.

  Command line (run from the project root):
    lua5.4 code/kit/census.lua   every registered gate has a per-gate test
                                 that passes; the type graph is acyclic.
                                 Final line "census: G gates, T tested,
                                 U untested"; exit 0 only when clean.
    lua5.4 code/kit/lint.lua     purity and layering over the project's
                                 pure layers, from the project's module
                                 `lint_config` (layers, pure layers,
                                 exceptions with reasons). Final line
                                 "lint: F files, N findings, X excused,
                                 S stale exceptions"; exit 0 only when
                                 N = 0 and S = 0.
    lua5.4 code/kit/run.lua      every suite in the tests directory, each
                                 gate test in two processes with agreeing
                                 digests. Final line "run: S suites, P/T
                                 passed"; exit 0 only when all pass.

  The register list: module `register` returns a list of gate module
  names; each gate module returns a core Gate whose `shape` declares
  { emits = {…}, rejects = {…} }.

check(modules) runs the module half of this contract against
`{ data = …, sha256 = …, helper = …, freeze = … }` and returns a list of
problems. `freeze` (core.freeze) is optional; when given, the check also
requires encode to read frozen data as the plain data it presents.
]]

local M = {}

M.EXPORTS = {
  data = { null = "any", array = "function", is_array = "function", encode = "function", decode = "function" },
  sha256 = { hex = "function" },
  helper = {
    gate = "function", event = "function", error = "function", accept = "function",
    abort = "function", drop = "function", guard = "function", check = "function", done = "function",
  },
}

M.TOOLS = { "code/kit/census.lua", "code/kit/lint.lua", "code/kit/run.lua" }

function M.check(mods)
  local p = {}
  local function fail(s) p[#p + 1] = s end
  for name, exports in pairs(M.EXPORTS) do
    local m = mods[name]
    if type(m) ~= "table" then fail("kit." .. name .. " is missing") else
      for k, kind in pairs(exports) do
        if m[k] == nil then fail("kit." .. name .. "." .. k .. " is missing")
        elseif kind ~= "any" and type(m[k]) ~= kind then fail("kit." .. name .. "." .. k .. " is not a " .. kind) end
      end
    end
  end
  table.sort(p)
  if #p > 0 then return p end

  local d, sha = mods.data, mods.sha256
  local ok, err = pcall(function()
    local v = { b = 1, a = d.array({ 1.0, "x", true, d.null }), e = d.array({}) }
    local s = d.encode(v)
    if s ~= '{"a":[1.0,"x",true,null],"b":1,"e":[]}' then fail("data.encode is not canonical: " .. tostring(s)) end
    local back = d.decode(s)
    if type(back) ~= "table" or not d.is_array(back.a) or not d.is_array(back.e) then fail("data.decode loses array marks") end
    if back.a and (math.type(back.a[1]) ~= "float" or back.a[4] ~= d.null) then fail("data.decode loses a float or null") end
    if back.b and math.type(back.b) ~= "integer" then fail("data.decode turns an integer into a float") end
    if d.is_array({}) then fail("an unmarked table is an array") end
    if getmetatable(d.null) ~= "readonly:kit.data.null" then
      fail("getmetatable(data.null) answers " .. tostring(getmetatable(d.null)) .. ", not \"readonly:kit.data.null\"")
    end
    local marked = d.array({})
    if getmetatable(marked) ~= "kit.data.array" then
      fail("a marked array answers getmetatable with " .. tostring(getmetatable(marked)) .. ", not \"kit.data.array\"")
    end
    if not rawequal(d.array(marked), marked) then fail("data.array on a marked array is not that array") end
    if pcall(function() d.null.x = 1 end) then fail("data.null accepts assignment") end
    local bad = d.encode({ f = function() end })
    if bad ~= nil then fail("data.encode accepted a function") end
    if mods.freeze then
      local function sample()
        return { b = 1, a = d.array({ 1.0, "x", true, d.null }), e = d.array({}), o = { k = "v", n = { 2 } }, l = { 1, 2 } }
      end
      local plain = d.encode(sample())
      local frozenSample = mods.freeze(sample())
      local frozen, why = d.encode(frozenSample)
      if frozen ~= plain then
        fail("data.encode does not read frozen data as the plain data it presents: " .. tostring(frozen or why))
      end
      if not rawequal(frozenSample.a[4], d.null) then fail("freezing did not pass data.null through") end
      if not d.is_array(frozenSample.a) then fail("a frozen marked array is not an array") end
    end
    if sha.hex("abc") ~= "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad" then
      fail("sha256.hex is wrong")
    end
  end)
  if not ok then fail("raised " .. tostring(err)) end
  return p
end

return M
