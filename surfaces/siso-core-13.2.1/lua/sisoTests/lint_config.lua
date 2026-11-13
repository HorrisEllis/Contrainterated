-- lint_config — this project's purity and layering map for code/kit/lint.lua
-- (contract kit-api). The lint finds this module on package.path.
return {
  source = "code/",

  -- layer → where it lives and the modules it may require
  layers = {
    core      = { path = "code/core/",      requires = { "core.*" } },
    observer  = { path = "code/observer/",  requires = { "observer.*" } },
    contracts = { path = "code/contracts/", requires = {} },
    kit       = { path = "code/kit/",       requires = { "kit.*", "core.event", "core.gate", "contracts.*", "harness" } },
    fixtures  = { path = "code/fixtures/",  requires = { "core.*", "observer.*", "contracts.*", "kit.*", "harness" } },
    harness   = { path = "code/harness.lua", requires = {} },
  },

  -- the layers that get the purity rules. Only the core: the observer keeps
  -- entries, a sequence and labels by design (observer-side state, ledger
  -- R2), so the purity rules cannot hold there; it gets the layer rules,
  -- and sisoTests/streamlog.test.lua pins that two logs share nothing (no
  -- module-global counter).
  pure = { "core" },

  -- where the pure layers may read `pairs` and `next`: the core's own key
  -- counting and freezing are order-free; the rule exists for gates, and
  -- the core ships none
  pairs_next = { "code/core/" },

  exceptions = {},
}
