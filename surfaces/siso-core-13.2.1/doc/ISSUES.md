# Open issues

Open items only. A fixed item is deleted; the settlement log says it was
fixed.

## Kit (minor)

- A per-gate test is code: it can still print a correct-looking
  "register:" line while having read another register. The census's
  run-time check catches every accidental route (package.path,
  KIT_REGISTER, a missing line), not a deliberate forgery. Closing it
  would need the helper to sign or the census to load the register
  itself; probably not worth it.
- lua/code/kit/census.lua reads the register line from the first of a gate
  test's two digest runs only. Fix: check both.
- lua/sisoTests/kit-helper.test.lua passes 55/60 when run from outside
  `lua/` (paths relative to the working directory). Every Lua suite is
  documented as run from `lua/` (spec Identity); fix only if a consumer
  needs it.

## For consumers moving to core 13.0.0 or 13.1.0 (jää first; cross-project, not this project's work)

- 13.1.0 moves the Lua reference: vendor `lua/code/core/`,
  `lua/code/kit/` and `lua/code/harness.lua` instead of `code/…`, and
  read the version from `VERSION` at the zip's project root. Module
  names and behaviour are unchanged; re-pin the layer guard's hashes
  against the new paths.

jää 2.16.0 vendors core 12.0.1. Moving to 13.0.0 (settlement logs
v13.0.0-rc1 and v13.0.0, *For consumers*):

- Live code, one site: `code/lib/util.lua` `deep_copy` (line 233) puts a
  mark back with `setmetatable(out, mt)`, which fails now that marks are
  strings. Use `kit.data.is_array` / `data.array`, and pass "readonly:"
  sentinels through. A trial of that change made every query answer
  again.
- Test files that write to built events, or adjust an event after using
  it once, now raise: `jaa-delete_commit_read`, `jaa-delete_read`,
  `jaa-drop_table_commit_read`, `jaa-insert_commit_read`,
  `jaa-insert_read`, `jaa-update_commit_read`, `jaa-update_read`,
  `jaa-upsert_conflict_read` (each aborts at its first site, so there may
  be more behind it). Build the data, adjust it, then build the event.
- `jaa-query_read.test.lua:594` builds event data whose `__index` raises,
  to show a gate never triggers metamethods; `Event()` now asks such a
  table for freeze's key while building, so it raises at construction.
  Plain data is unaffected.
- `substrate/rel.test.lua:356,374` copy a mark with `setmetatable`: same
  fix as `deep_copy`.
- The layer guard pins the vendored core and kit files' hashes: re-pin.
- jää's own `json.null` (`__readonly = true`) still passes freezing but
  hands out its writable metatable (spec R4): move it to the string form
  or use `kit.data.null`.
- Answers to jää's requests: V-6 fixed (a gate's deep output is a
  rejection; an entry too deep to copy is SISO_NOT_EVENT); C-2 fixed
  (each observer gets a plain read-only copy, read `.message`).
- jää's third request, K5's cost wording (a cycle is detected about G/L
  laps late), is a change to `doc/SISO-principles.md`, which the user
  owns: open for the user.

## Deception by trusted code (named, not fixed)

The core turns honest mistakes into loud failures. What follows takes
code written to deceive, inside the trusted process; outside input is
data and cannot reach any of it. The user's call is to name these, not
add machinery, unless that changes.

- X1's family, found while auditing 13.2.0: `freeze.plain` reads a
  thrown table through `pairs` once per observer, so a thrown table's
  `__pairs` (code of the gate's) runs once per attached observer (0, 1, 3
  runs with 0, 1, 3 observers): the gate can count the observers, and
  their copies can differ (checklist 12). The Python port (since 13.2.0)
  and the JavaScript port (since 13.2.1) take one snapshot per rejection
  and copy it per observer; the same fix applies here.
- X1 (spec, recorded departure): a lying `__len`; a forged event (a
  table answering "siso.event" by hand), including one whose `__index`
  raises when `judge` reads its type (emit then raises it raw); a thrown
  table whose `__index` raises; freeze's key captured by a crafted
  `__index`, including through `pairs`' iterator, giving writes past the
  refusal that the lint cannot see.
- R3: `rawset` and the debug library past a frozen table or `data.null`;
  a gate's upvalues, globals (the string metatable among them), I/O, and
  `coroutine.yield` from inside a propagation (it suspends it; a gate
  registered meanwhile can make a later delivery look like a cycle).
- R5: code holding a stream reaches its fields and the shared stream
  metatable.
- `freeze` recurses without a depth bound: a host-built event deep
  enough overflows in the host's own `Event()` call (about 62,000 levels
  on Lua 5.4); an entry Event accepted but too deep to copy is refused.

## Lua (comment only)

- lua/code/kit/data.lua's header says floats are laid out in decimal
  "for 1e-7 <= |x| < 1e21"; the code (and ECMAScript, and all four kits)
  uses 1e-6 as the lower bound (1e-7 renders "1e-7"). Fix the comment.

## Observer (minor)

- lua/code/observer/streamlog.lua's header (and lua/sisoTests/streamlog.test.lua's)
  still describes a batch's
  rejection as "<raised table>" and says "core 12 observer seam"; since
  core 13 it is the observer's plain copy. Comment only.

## JavaScript and PHP ports (minor)

- php/code/contracts/core_api.php EVENT and REJECTION do not pin two
  messages the port chose: a recursive array (possible only through a
  PHP reference, `$a['self'] = &$a`) is refused as "not plain data
  (recursive array)", and a resource a gate returns is described as "a
  resource (stream)". Pin them in the contract if a consumer relies on
  the text (tests assert codes, never messages).
- php-core-api SUBSTRATE says an entry the stream cannot copy is
  SISO_NOT_EVENT. With the iterative copy no depth that `Event` accepts
  fails to copy, so the path is reached only by an Event forged through
  Reflection to contain itself (P2). Keep the guard; say so in the
  contract at its next change.
- php/sisoTests/contract-fixture_format.test.php declares a stand-in
  `Siso\Kit\Json` when none exists, so the contract test does not
  depend on the kit (a contract must not reach a side). If it were ever
  loaded in the same process before php/code/kit/data.php, the real
  declaration would fail ("cannot redeclare"). Suites run one per
  process, so nothing does; fix if that changes.
- js/sisoTests/layers.test.mjs reads source text: a regex literal right
  after `)` or `}` is read as division; code loaded through `eval`,
  `Function` or `createRequire` is not seen; a method named `import` is
  judged as a dynamic import. Its header lists these. M6 and the suite
  retire if the census reads layers inside a boundary.
- php/sisoTests/layers.test.php checks inclusions only: a class from
  another layer named without an inclusion (`\Siso\Kit\…` in core/) is
  not caught. Fix: a NAME rule mapping the `Siso\Core|Observer|Contracts|
  Kit|Fixtures|Harness` namespaces to layers, applied to qualified names
  and `use` statements.

## Deception by trusted code in the ports (named, not fixed)

As for the reference: what follows takes trusted code using the
language's debugging or global-rewriting facilities; outside input is
data and reaches none of it.

- J2: an index accessor installed on `Array.prototype` (or
  `Object.prototype`) is handed the JS core's internal arrays when the
  core writes a missing index, and can append to the residue. Fix if
  wanted: write private arrays with a captured `Object.defineProperty`
  (no setter runs) or keep them null-prototype; or the host freezes its
  intrinsics.
- J6: a Proxy handed to the JS core answers what it likes; the core
  reads it once and carries or refuses exactly that.
- P2: `debug_backtrace()` shows a gate or observer the Stream (emit's
  `$this`); Reflection and closures bound into the class then read and
  write its private state, and can build an unvalidated Event.
- P8: a resource inside what a gate threw is the same handle in every
  observer's rejection copy (as Lua userdata is).

## Deferred

- Dev notes §1 (an implementation-guidance appendix) stays deferred until
  more than one implementation shows the patterns.
- follow-up: lua/code/core/stream.lua (the reference) still copies per observer (batchFor calls freeze.plain per observer), so the same observer-counting flaw stands there; suggested fix: the matching Lua phase takes one snapshot in `reject` as here. (from phase js-cause-snapshot)
