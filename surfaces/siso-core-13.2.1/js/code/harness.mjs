/*
harness — the test harness every JavaScript suite reports through.

Port of lua/code/harness.lua, itself the port of core 11.1
`tests/runner.js` (doc/log/legacy-js/tests/runner.js), unchanged in
behaviour. Shared platform: no phase writes it.

  import * as H from "../code/harness.mjs";
  H.test(name, fn)  H.assert(c, msg)  H.assertEqual(a, e, label)
  H.assertClose(a, e, epsilon, label)  H.assertThrows(fn, substring, label)
  H.fail(msg)  H.show(v)  H.counts()  H.report(suite)  H.finish(suite)
  H.reset()

Final line: "{suite}: {passed}/{total} passed"
        or  "{suite}: {passed}/{total} passed, {failed} FAILED"
finish() prints the failures before that line (as the Lua harness does,
so the summary is always the LAST line; runner.js printed them after it)
and exits with code 0 when every test passed, else 1.

Assertion failures are thrown as the harness's own AssertionFailure, so
the report shows the message alone; any other thrown value is reported
with its own message (an object with a `message`, such as an Error or a
rejection, shows that message; anything else as String gives it,
guarded).

Tests are synchronous, as in the reference: a test whose function
returns a thenable (an async function) is counted as failed, since its
outcome would arrive after the test was counted.

SUBSTRATE
  assertEqual compares with === (the original runner's comparison). JS
  has no integer/float subtype, so Lua's "1 and 1.0 differ" clause
  vanishes; NaN is unequal to itself and +0 === -0, as === says.
  show() renders with JSON.stringify where it can (the original
  runner's rendering), guarded against cycles, bigints and undefined.
*/

let passed = 0;
let failed = 0;
let failures = [];

class AssertionFailure extends Error {}

function failWith(message) {
  throw new AssertionFailure(message);
}

function str(v) {
  try {
    return String(v);
  } catch {
    return Object.prototype.toString.call(v);
  }
}

function messageOf(err) {
  if (err !== null && (typeof err === "object" || typeof err === "function")) {
    let m;
    try {
      m = err.message;
    } catch {
      m = undefined;
    }
    if (m !== undefined) return str(m);
  }
  return str(err);
}

// JSON-ish rendering for failure messages (runner.js used
// JSON.stringify). Only for messages; never for comparison.
export function show(v) {
  if (v === undefined) return "undefined";
  if (typeof v === "bigint") return `${v}n`;
  if (typeof v === "symbol" || typeof v === "function") return str(v);
  if (typeof v === "number" && !Number.isFinite(v)) return String(v);
  if (Object.is(v, -0)) return "-0";
  try {
    const s = JSON.stringify(v);
    return s === undefined ? str(v) : s;
  } catch {
    return str(v);
  }
}

export function test(name, fn) {
  try {
    const r = fn();
    if (r !== null && (typeof r === "object" || typeof r === "function") && typeof r.then === "function") {
      failWith("the test returned a thenable; tests are synchronous");
    }
    passed += 1;
  } catch (err) {
    failed += 1;
    const message = messageOf(err);
    failures.push({ name, message });
    console.log(`  ✗  ${name}`);
    console.log(`     ${message}`);
  }
}

export function assert(condition, message) {
  if (!condition) failWith(message === undefined ? "assertion failed" : str(message));
}

export function assertEqual(actual, expected, label) {
  if (actual !== expected) {
    const prefix = label ? `${label}: ` : "";
    failWith(`${prefix}expected ${show(expected)}, got ${show(actual)}`);
  }
}

export function assertClose(actual, expected, epsilon = 1e-10, label) {
  if (!(Math.abs(actual - expected) <= epsilon)) {
    const prefix = label ? `${label}: ` : "";
    failWith(`${prefix}expected ≈${show(expected)}, got ${show(actual)}`);
  }
}

export function assertThrows(fn, substring, label) {
  const prefix = label ? `${label}: ` : "";
  let threw = false;
  let err;
  try {
    fn();
  } catch (e) {
    threw = true;
    err = e;
  }
  if (!threw) failWith(`${prefix}expected error, none thrown`);
  const message = messageOf(err);
  if (substring && !message.includes(substring)) {
    failWith(`${prefix}expected error containing '${substring}', got '${message}'`);
  }
}

export function fail(message) {
  failWith(message);
}

export function counts() {
  return [passed, failed];
}

export function report(suite) {
  const total = passed + failed;
  console.log();
  if (failed === 0) {
    console.log(`${suite}: ${passed}/${total} passed`);
  } else {
    // the failures first, so the summary is always the last line
    for (const f of failures) console.log(`  FAIL: ${f.name} — ${f.message}`);
    console.log(`${suite}: ${passed}/${total} passed, ${failed} FAILED`);
  }
  return failed;
}

// report() and exit with the original runner's code (0 on pass).
export function finish(suite) {
  const n = report(suite);
  process.exit(n === 0 ? 0 : 1);
}

export function reset() {
  passed = 0;
  failed = 0;
  failures = [];
}
