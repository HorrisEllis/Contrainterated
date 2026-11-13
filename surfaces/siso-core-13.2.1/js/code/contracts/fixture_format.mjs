/*
contract: fixture-format — the language-agnostic suite every instance
must pass (core 12; JavaScript instance). Port of
lua/code/contracts/fixture_format.lua.

Between: fixtures -> stream, observer (and every instance: the Lua
reference, this port and the PHP port are checked against the same
files). Only a contracts phase changes this file. The fixture files
themselves (P/fixtures/*.json) are the fixtures boundary's; this is the
format they are written in, and the matching rules every runner applies.

A FIXTURE is one JSON file:
  {
    "format": 1,
    "name":   "a sentence",
    "covers": ["Rule 3", "K5", …],      principle items it pins
    "gates":  [ gate, … ],              registered before the first step
    "steps":  [ step, … ]               run in order
  }
A GATE is { "signature": "a", "does": behaviour }. Behaviours are
test-only (dev notes §2):
  { "emit": [ out, … ] }   returns an array of these (empty: consumed)
  { "emitOne": out }       returns one Event
  { "forward": "t" }       returns Event("t", <the received event's data>)
  { "nothing": true }      returns undefined
  { "throw": "message" }   throws the string "message" itself (so the
                           rejection's `cause` is that string)
  { "raw": value }         returns `value` itself (a fresh copy of the
                           decoded JSON value), which is not an Event
  where `out` is an EVENT, or { "raw": value } for an array member that
  is not an Event.
An EVENT is { "type": "t", "data": { … } }; "data" may be omitted ({}).
Event data is plain JSON with integers only (no floats), and no empty
arrays inside it (Lua cannot tell [] from {}). The restriction is on event
data only: a list the format itself expects (gates, steps, emit, take,
sample's residue, trace, then, at) may be empty, so "take": [] pins that
the stream holds no residue.

A STEP is one of:
  { "register": gate, "refused": true|false }
  { "emit": EVENT, "expect": expectation }
  { "emitRaw": value, "expect": expectation }   the entry is not an Event
  { "take": [ EVENT, … ] }        the residue, compared as a multiset
  { "sample": { "residue": [ EVENT, … ], "gateCount": n } }
                                  the residue compared as a multiset
An EXPECTATION is:
  "settled"
  { "settled": true, "trace": [ node, … ] }
  { "rejected": { "code": "SISO_…", "eventType"?: "t", "depth"?: n,
                  "cause"?: "message", "at"?: [ "t1", "t2", … ] },
    "trace"?: [ node, … ] }
A NODE is one delivery of the propagation, as the observer's batch shows
it: { "type": "t", "claimed": true|false, "then"?: [ node, … ] }, where
"then" lists the deliveries its gate's return caused.

MATCHING (order-free: K1 is not semantics)
  - Residue is a multiset: two lists match when, rendered canonically,
    they hold the same events the same number of times.
  - A trace is a forest built from the batch's records by causeId. Two
    forests match when their roots match as a multiset and each node's
    "then" matches as a multiset, recursively.
  - "at" names the delivery a rejection names, by the path of types
    from the entry delivery (["a", "b"]: the b caused by the entry a).
    The rejection's eventId must be that record's id, and its causeId
    that record's cause. The fixture's author keeps the path unambiguous.
  - "eventType", "depth" and "cause" (the thrown string) compare exactly
    (===) when present. An expectation never names a raw id: ids follow
    delivery order, which an instance may choose.
  - A runner always attaches one observer, and fails a step whose batch
    breaks observer-seam (batchProblems), whose rejection breaks
    core-api (rejectionProblems), or whose batch's rejection is not the
    observer's own copy (rejectionCopyProblem).

SUBSTRATE (JavaScript; how the JS runner, js/code/fixtures/run.mjs, maps
the files)
  - Files are decoded with kit/data.mjs (strict RFC 8259) or JSON.parse;
    JSON objects are plain objects, arrays are Arrays, null is null.
  - JSON numbers are JS numbers: "1.0" decodes to 1, so the "integers
    only" rule is enforced only for non-integral numbers (1.5 is caught,
    1.0 cannot be told from 1). The fixtures hold integers only.
  - Unlike Lua, JS can tell [] from {}: problems() also names an empty
    array inside event data (the format forbids it), and canon renders
    arrays as [..] and objects as {..}.
  - "throw" throws the string itself (`throw "message"`), so `cause`
    compares to it with ===.
  - "raw" values and event data are copied fresh from the decoded file
    for each call, so no gate shares an object with the fixture or with
    another call.
  - A record's absent causeId (the entry) is `undefined`.
  - JSON null as a raw value, or as a whole emitRaw entry, is a value
    (`"emitRaw": null` enters null): presence is judged by key, not by
    value. `"raw": null` returns null, which a JS gate's return means
    "consumed", while Lua's runner returns its null sentinel (a table,
    SISO_NOT_EVENT): the instances differ there, so a fixture must not
    use a bare `"raw": null` as a gate behaviour (none does).
  - Event "data" must be a JSON object when present (null and arrays are
    named as shape problems; Lua's decoder made null a table).
  - The matching functions return pairs in kit/data.mjs's style:
    residueMatch and traceMatch → [true, null] | [false, why];
    recordAt → [record, null] | [null, why].
  - Records are found by eventId (forest, recordAt), which on a batch
    that passes batchProblems is the record's position plus one, as the
    reference indexes them.

problems(fixture) checks a decoded fixture's shape. canon(v),
residueMatch(expected, actual), traceMatch(expected, records),
forest(records) and recordAt(records, path) are the matching rules, for
JavaScript runners.
*/

const BEHAVIOURS = Object.freeze({ emit: true, emitOne: true, forward: true, nothing: true, throw: true, raw: true });
const CODES = Object.freeze({ SISO_SELF_LOOP: true, SISO_CYCLE: true, SISO_GATE_THREW: true, SISO_NOT_EVENT: true });

const hasOwn = (o, k) => Object.prototype.hasOwnProperty.call(o, k);
const isObject = (v) => v !== null && typeof v === "object" && !Array.isArray(v);

function str(v) {
  try {
    return String(v);
  } catch {
    return Object.prototype.toString.call(v);
  }
}

function isList(t) {
  return Array.isArray(t) && Object.keys(t).length === t.length;
}

const byString = (a, b) => (a < b ? -1 : a > b ? 1 : 0);

// A canonical rendering of plain data: keys sorted, integers only.
// Throws an Error naming what is not fixture data.
export function canon(v) {
  const onPath = new Set();
  const render = (x) => {
    const t = typeof x;
    if (t === "string") return JSON.stringify(x);
    if (t === "number") {
      if (!Number.isInteger(x)) throw new Error(`fixture data holds a float: ${str(x)}`);
      return String(x);
    }
    if (t === "boolean") return String(x);
    if (x === null) return "null";
    if (t === "object") {
      if (onPath.has(x)) throw new Error("not plain data: a cycle");
      onPath.add(x);
      let out;
      if (Array.isArray(x)) {
        const parts = [];
        for (let i = 0; i < x.length; i += 1) parts.push(render(x[i]));
        out = `[${parts.join(",")}]`;
      } else {
        const parts = Object.keys(x).sort(byString).map((k) => `${JSON.stringify(k)}:${render(x[k])}`);
        out = `{${parts.join(",")}}`;
      }
      onPath.delete(x);
      return out;
    }
    throw new Error(`not plain data: ${t}`);
  };
  return render(v);
}

function renderEvent(e) {
  return canon({ type: e.type, data: e.data === undefined ? {} : e.data });
}

function multiset(list, render) {
  return list.map(render).sort(byString).join("|");
}

export function residueMatch(expected, actual) {
  const a = multiset(expected, renderEvent);
  const b = multiset(actual, renderEvent);
  if (a === b) return [true, null];
  return [false, `residue: expected ${a}, got ${b}`];
}

function renderNode(n) {
  return `(${canon(n.type)}${n.claimed ? "+" : "-"}[${multiset(n.then === undefined ? [] : n.then, renderNode)}])`;
}

// the forest of a batch's records, as nodes
export function forest(records) {
  const byId = new Map();
  const roots = [];
  for (const r of records) {
    const node = { type: r.eventType, claimed: r.claimed, then: [] };
    byId.set(r.eventId, node);
    if (r.causeId === undefined) roots.push(node);
    else {
      const parent = byId.get(r.causeId);
      if (parent === undefined) throw new Error(`record ${str(r.eventId)}: causeId ${str(r.causeId)} names no earlier delivery`);
      parent.then.push(node);
    }
  }
  return roots;
}

export function traceMatch(expected, records) {
  const a = multiset(expected, renderNode);
  const b = multiset(forest(records), renderNode);
  if (a === b) return [true, null];
  return [false, `trace: expected ${a}, got ${b}`];
}

// the record reached by a path of types from the entry; [null, why]
// when the path matches none or more than one
export function recordAt(records, path) {
  let frontier = records.filter((r) => r.causeId === undefined && r.eventType === path[0]).map((r) => r.eventId);
  for (let depth = 1; depth < path.length; depth += 1) {
    const at = new Set(frontier);
    frontier = records.filter((r) => at.has(r.causeId) && r.eventType === path[depth]).map((r) => r.eventId);
  }
  if (frontier.length !== 1) return [null, `${frontier.length} deliveries match the path`];
  return [records.find((r) => r.eventId === frontier[0]), null];
}

// ── shape ───────────────────────────────────────────────────────

// the path of the first empty array inside v, or null
function emptyArrayIn(v, path) {
  const seen = new Set();
  const stack = [[v, path]];
  while (stack.length > 0) {
    const [x, where] = stack.pop();
    if (x === null || typeof x !== "object" || seen.has(x)) continue;
    seen.add(x);
    if (Array.isArray(x)) {
      if (x.length === 0) return where;
      x.forEach((y, i) => stack.push([y, `${where}[${i}]`]));
    } else {
      for (const k of Object.keys(x)) stack.push([x[k], `${where}.${k}`]);
    }
  }
  return null;
}

function eventProblems(e, where, p) {
  if (!isObject(e) || typeof e.type !== "string") {
    p.push(`${where}: an event needs a string type`);
    return;
  }
  for (const k of Object.keys(e)) {
    if (k !== "type" && k !== "data") p.push(`${where}: unexpected field ${k}`);
  }
  if (e.data !== undefined && !isObject(e.data)) p.push(`${where}: data is not an object`);
  if (e.data !== undefined) {
    try {
      canon(e.data);
    } catch (err) {
      p.push(`${where}: ${err.message}`);
    }
    const empty = emptyArrayIn(e.data, "data");
    if (empty !== null) p.push(`${where}: ${empty} is an empty array (event data holds none: Lua cannot tell [] from {})`);
  }
}

function outProblems(o, where, p) {
  if (isObject(o) && hasOwn(o, "raw") && o.type === undefined) return;
  eventProblems(o, where, p);
}

function gateProblems(g, where, p) {
  if (!isObject(g) || typeof g.signature !== "string") {
    p.push(`${where}: a gate needs a string signature`);
    return;
  }
  const d = g.does;
  if (!isObject(d)) {
    p.push(`${where}: no behaviour`);
    return;
  }
  const keys = Object.keys(d).sort(byString);
  if (keys.length !== 1 || !hasOwn(BEHAVIOURS, keys[0])) {
    p.push(`${where}: a behaviour is exactly one of emit, emitOne, forward, nothing, throw, raw`);
    return;
  }
  if (d.emit !== undefined) {
    if (!isList(d.emit)) p.push(`${where}: emit takes a list`);
    else d.emit.forEach((o, i) => outProblems(o, `${where}.emit[${i + 1}]`, p));
  }
  if (d.emitOne !== undefined) eventProblems(d.emitOne, `${where}.emitOne`, p);
  if (d.forward !== undefined && typeof d.forward !== "string") p.push(`${where}: forward names a type`);
  if (d.throw !== undefined && typeof d.throw !== "string") p.push(`${where}: throw takes a string`);
  if (hasOwn(d, "raw") && d.raw === null) p.push(`${where}: raw null means "consumed" in JS and SISO_NOT_EVENT in Lua; use nothing or another raw value`);
}

function nodeProblems(n, where, p) {
  if (!isObject(n) || typeof n.type !== "string" || typeof n.claimed !== "boolean") {
    p.push(`${where}: a node is { type, claimed, then? }`);
    return;
  }
  if (n.then !== undefined) {
    if (!isList(n.then)) p.push(`${where}: then is a list`);
    else n.then.forEach((c, i) => nodeProblems(c, `${where}.then[${i + 1}]`, p));
  }
}

function expectProblems(x, where, p) {
  if (x === "settled") return;
  if (!isObject(x)) {
    p.push(`${where}: expect is "settled" or an object`);
    return;
  }
  if ((x.settled !== undefined) === (x.rejected !== undefined)) p.push(`${where}: expect exactly one of settled, rejected`);
  if (x.rejected !== undefined) {
    const r = x.rejected;
    if (!isObject(r) || typeof r.code !== "string" || !hasOwn(CODES, r.code)) p.push(`${where}: rejected needs a known code`);
    else {
      if (r.at !== undefined && !isList(r.at)) p.push(`${where}: at is a list of types`);
      if (r.depth !== undefined && !Number.isInteger(r.depth)) p.push(`${where}: depth is an integer`);
    }
  }
  if (x.trace !== undefined) {
    if (!isList(x.trace)) p.push(`${where}: trace is a list`);
    else x.trace.forEach((n, i) => nodeProblems(n, `${where}.trace[${i + 1}]`, p));
  }
}

export function problems(f) {
  const p = [];
  if (!isObject(f)) return ["a fixture is an object"];
  if (f.format !== 1) p.push("format is not 1");
  if (typeof f.name !== "string") p.push("no name");
  if (!isList(f.covers) || f.covers.length === 0) p.push("covers lists the items it pins");
  if (!isList(f.gates)) p.push("gates is a list");
  else f.gates.forEach((g, i) => gateProblems(g, `gates[${i + 1}]`, p));
  if (!isList(f.steps) || f.steps.length === 0) {
    p.push("steps is a non-empty list");
    return p;
  }
  f.steps.forEach((s, i) => {
    const where = `steps[${i + 1}]`;
    if (!isObject(s)) p.push(`${where}: not an object`);
    else if (s.register !== undefined) {
      gateProblems(s.register, `${where}.register`, p);
      if (typeof s.refused !== "boolean") p.push(`${where}: register says whether it is refused`);
    } else if (s.emit !== undefined) {
      eventProblems(s.emit, `${where}.emit`, p);
      expectProblems(s.expect, where, p);
    } else if (hasOwn(s, "emitRaw")) {
      expectProblems(s.expect, where, p);
    } else if (s.take !== undefined) {
      if (!isList(s.take)) p.push(`${where}: take is a list`);
      else s.take.forEach((e, j) => eventProblems(e, `${where}.take[${j + 1}]`, p));
    } else if (s.sample !== undefined) {
      if (!isObject(s.sample) || !isList(s.sample.residue) || !Number.isInteger(s.sample.gateCount)) {
        p.push(`${where}: sample is { residue: [...], gateCount: n }`);
      }
    } else {
      p.push(`${where}: a step is one of register, emit, emitRaw, take, sample`);
    }
  });
  return p;
}
