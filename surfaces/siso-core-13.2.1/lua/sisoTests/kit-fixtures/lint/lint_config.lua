-- The lint fixtures' own config: layer names unlike this project's, so
-- the tests show the lint takes everything project-specific from here.
return {
  source = "code/",
  layers = {
    base   = { path = "code/base/",      requires = { "base.*" } },
    lib    = { path = "code/lib/",       requires = { "base.*", "lib.*" } },
    gates  = { path = "code/gates/",     requires = { "base.event", "base.gate", "lib.*" } },
    io     = { path = "code/io/",        requires = { "lib.*", "io.*" } },
    runner = { path = "code/runner.lua", requires = { "base.*", "lib.*", "io.*" } },
  },
  pure = { "base", "lib", "gates" },
  pairs_next = { "code/lib/util.lua" },
  -- for the fixture project in project/: one used, one stale, one for a
  -- file that is not there (neither used nor stale)
  exceptions = {
    { file = "code/gates/counter.lua", rule = "UPVALUE_WRITE", name = "calls", reason = "fixture: excused" },
    { file = "code/gates/ok.lua", rule = "GLOBAL_READ", name = "os", reason = "fixture: stale" },
    { file = "code/gates/gone.lua", rule = "RANDOM", name = "math.random", reason = "fixture: not linted" },
  },
}
