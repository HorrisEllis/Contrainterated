-- Fixture register for sisoTests/kit-helper.test.lua (not a project's
-- gates: fx: signatures). Read through helper.use_register or KIT_REGISTER
-- = "kit-fixtures.helper.register". Order carries no meaning.
--   gates/        fx_double (accept / abort), fx_route (a classifier),
--                 fx_skip (accept / drop)
--   tests/        a tests directory for code/kit/run.lua: one gate test
--                 (fx-double), one plain suite, and fixtures/ that run.lua
--                 must never enter
--   scatter/      a gate test for fx_scatter (register_scatter.lua): its
--                 output follows `pairs` order, so each process passes and
--                 two processes disagree
return {
  "kit-fixtures.helper.gates.fx_double",
  "kit-fixtures.helper.gates.fx_route",
  "kit-fixtures.helper.gates.fx_skip",
}
