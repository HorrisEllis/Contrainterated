import { test, assert, assertEqual, assertThrows, report } from './runner.js';
import { Event } from '../code/src/Event.js';
import { Gate } from '../code/src/Gate.js';
import { Stream } from '../code/src/Stream.js';

// ── Event ──────────────────────────────────────

test('Event: type and data', () => {
  const e = new Event('foo', { x: 1 });
  assertEqual(e.type, 'foo');
  assertEqual(e.data.x, 1);
});

test('Event: data defaults to empty object', () => {
  const e = new Event('bar');
  assertEqual(e.type, 'bar');
  assert(typeof e.data === 'object');
  assertEqual(Object.keys(e.data).length, 0);
});

// ── Gate ───────────────────────────────────────

test('Gate: signature stored', () => {
  const g = new Gate('test_sig');
  assertEqual(g.signature, 'test_sig');
});

test('Gate: transform returns undefined by default', () => {
  const g = new Gate('noop');
  const result = g.transform(new Event('noop'));
  assertEqual(result, undefined);
});

// ── Stream: registration ───────────────────────

test('Stream: register and lookup', () => {
  const s = new Stream();
  const g = new Gate('x');
  s.register(g);
  assert(s.gates.has('x'));
});

test('Stream: signature collision throws', () => {
  const s = new Stream();
  s.register(new Gate('x'));
  assertThrows(() => s.register(new Gate('x')), 'Signature collision');
});

test('Stream: different signatures coexist', () => {
  const s = new Stream();
  s.register(new Gate('a'));
  s.register(new Gate('b'));
  assertEqual(s.gates.size, 2);
});

// ── Stream: emit and residue ───────────────────

test('Stream: unclaimed event goes to residue', () => {
  const s = new Stream();
  s.emit(new Event('unknown', { v: 42 }));
  const sample = s.sampleHere();
  assertEqual(sample.residue.length, 1);
  assertEqual(sample.residue[0].type, 'unknown');
  assertEqual(sample.residue[0].data.v, 42);
});

test('Stream: claimed event does not go to residue', () => {
  class Sink extends Gate {
    constructor() { super('sink'); }
    transform() { return null; }
  }
  const s = new Stream();
  s.register(new Sink());
  s.emit(new Event('sink', {}));
  assertEqual(s.sampleHere().residue.length, 0);
});

test('Stream: gate receives event only', () => {
  let transformArgs = null;
  class Spy extends Gate {
    constructor() { super('spy'); }
    transform(event) {
      transformArgs = {
        argCount: arguments.length,
        type: event.type,
        data: event.data,
      };
      return null;
    }
  }
  const s = new Stream();
  s.register(new Spy());
  s.emit(new Event('spy', { val: 99 }));
  assertEqual(transformArgs.argCount, 1);
  assertEqual(transformArgs.type, 'spy');
  assertEqual(transformArgs.data.val, 99);
});

test('Stream: gate returns single event → re-emitted', () => {
  class Doubler extends Gate {
    constructor() { super('double'); }
    transform(event) {
      return new Event('result', { value: event.data.x * 2 });
    }
  }
  const s = new Stream();
  s.register(new Doubler());
  s.emit(new Event('double', { x: 5 }));
  const sample = s.sampleHere();
  assertEqual(sample.residue.length, 1);
  assertEqual(sample.residue[0].data.value, 10);
});

test('Stream: gate returns array of events → all re-emitted', () => {
  class Splitter extends Gate {
    constructor() { super('split'); }
    transform(event) {
      return [
        new Event('part_a', { half: event.data.x / 2 }),
        new Event('part_b', { doubled: event.data.x * 2 }),
      ];
    }
  }
  const s = new Stream();
  s.register(new Splitter());
  s.emit(new Event('split', { x: 10 }));
  const sample = s.sampleHere();
  assertEqual(sample.residue.length, 2);
  assertEqual(sample.residue[0].data.half, 5);
  assertEqual(sample.residue[1].data.doubled, 20);
});

test('Stream: gate returns null → consumed, no follow-up', () => {
  class Consumer extends Gate {
    constructor() { super('consume'); }
    transform() { return null; }
  }
  const s = new Stream();
  s.register(new Consumer());
  s.emit(new Event('consume', {}));
  assertEqual(s.sampleHere().residue.length, 0);
  assertEqual(s.sampleHere().eventCount, 1);
});

test('Stream: gate returns undefined → same as null', () => {
  class Silent extends Gate {
    constructor() { super('silent'); }
    transform() { /* no return */ }
  }
  const s = new Stream();
  s.register(new Silent());
  s.emit(new Event('silent', {}));
  assertEqual(s.sampleHere().residue.length, 0);
});

test('Stream: chain processes in causal order', () => {
  const order = [];
  class A extends Gate {
    constructor() { super('a'); }
    transform(event) {
      order.push('a');
      return new Event('b', {});
    }
  }
  class B extends Gate {
    constructor() { super('b'); }
    transform(event) {
      order.push('b');
      return new Event('c', {});
    }
  }
  class C extends Gate {
    constructor() { super('c'); }
    transform(event) {
      order.push('c');
      return null;
    }
  }
  const s = new Stream();
  s.register(new A());
  s.register(new B());
  s.register(new C());
  s.emit(new Event('a', {}));
  assertEqual(order.join(','), 'a,b,c');
});

test('Stream: fan-out delivers all branches', () => {
  const order = [];
  class Root extends Gate {
    constructor() { super('root'); }
    transform() {
      return [
        new Event('left', {}),
        new Event('right', {}),
      ];
    }
  }
  class Left extends Gate {
    constructor() { super('left'); }
    transform() { order.push('left'); return null; }
  }
  class Right extends Gate {
    constructor() { super('right'); }
    transform() { order.push('right'); return null; }
  }
  const s = new Stream();
  s.register(new Root());
  s.register(new Left());
  s.register(new Right());
  s.emit(new Event('root', {}));
  assertEqual(order.length, 2);
  assert(order.includes('left'), 'left branch fired');
  assert(order.includes('right'), 'right branch fired');
});

// ── Stream: delivery discipline ────────────────

test('Stream: delivery is breadth-first — causal generations', () => {
  const order = [];
  const mk = (sig, out) => new (class extends Gate {
    constructor() { super(sig); }
    transform() {
      order.push(sig);
      return out ? out.map(t => new Event(t, {})) : null;
    }
  })();
  const s = new Stream();
  s.register(mk('root', ['a', 'b']));
  s.register(mk('a', ['a1']));
  s.register(mk('b', ['b1']));
  s.register(mk('a1', null));
  s.register(mk('b1', null));
  s.emit(new Event('root', {}));
  // Generation by generation: root, then both children,
  // then both grandchildren. Depth-first would give
  // root,a,a1,b,b1. Order is not semantic (Rule 3) —
  // this pins the implementation choice, not a contract.
  assertEqual(order.join(','), 'root,a,b,a1,b1');
});

test('Stream: chain length unbounded by call stack', () => {
  // A legal chain of N distinct gates: step_0 → step_1 → …
  // No type repeats, so acyclicity holds — chain length is
  // bounded by gate count, exactly as Rule 3 permits. The
  // recursive implementation overflowed the engine stack on
  // chains this long; queue delivery must not.
  const N = 20000;
  class Step extends Gate {
    constructor(i) { super(`step_${i}`); this.i = i; }
    transform() {
      return this.i < N - 1 ? new Event(`step_${this.i + 1}`, {}) : null;
    }
  }
  const s = new Stream();
  for (let i = 0; i < N; i++) s.register(new Step(i));
  s.emit(new Event('step_0', {}));
  assertEqual(s.sampleHere().eventCount, N);
  assertEqual(s.sampleHere().residue.length, 0);
});

test('Stream: events are never annotated by delivery', () => {
  class Pass extends Gate {
    constructor() { super('pass'); }
    transform() { return new Event('out', { v: 1 }); }
  }
  const s = new Stream();
  s.register(new Pass());
  const root = new Event('pass', {});
  s.emit(root);
  const out = s.sampleHere().residue[0];
  assertEqual(Object.keys(root).sort().join(','), 'data,type',
    'emitted event untouched');
  assertEqual(Object.keys(out).sort().join(','), 'data,type',
    'delivered event untouched');
});

// ── Event: shape ───────────────────────────────

test('Event: carries only type and data', () => {
  // Core 11 removed the retries field. A stray third argument
  // must not attach anything to the event.
  const e = new Event('foo', { x: 1 }, { attempt: 1, max: 3 });
  assertEqual(Object.keys(e).sort().join(','), 'data,type');
});

// ── Stream: acyclicity (enforced) ──────────────

test('Stream: self-loop throws SISO_SELF_LOOP', () => {
  class Echo extends Gate {
    constructor() { super('echo'); }
    transform(event) { return new Event('echo', { v: event.data.v + 1 }); }
  }
  const s = new Stream();
  s.register(new Echo());
  let err = null;
  try { s.emit(new Event('echo', { v: 0 })); } catch (e) { err = e; }
  assert(err, 'expected a throw');
  assertEqual(err.code, 'SISO_SELF_LOOP');
  assertEqual(err.eventType, 'echo');
  assertEqual(s.sampleHere().residue.length, 0, 'nothing committed');
});

test('Stream: self-loop inside a fan-out array throws', () => {
  class Mixed extends Gate {
    constructor() { super('mixed'); }
    transform() { return [new Event('other', {}), new Event('mixed', {})]; }
  }
  const s = new Stream();
  s.register(new Mixed());
  assertThrows(() => s.emit(new Event('mixed', {})), 'Self-loop');
});

test('Stream: indirect cycle a → b → a throws SISO_CYCLE', () => {
  const s = new Stream();
  s.register(new (class extends Gate {
    constructor() { super('a'); }
    transform() { return new Event('b', {}); }
  })());
  s.register(new (class extends Gate {
    constructor() { super('b'); }
    transform() { return new Event('a', {}); }
  })());
  let err = null;
  try { s.emit(new Event('a', {})); } catch (e) { err = e; }
  assert(err, 'expected a throw — core 10 looped forever here');
  assertEqual(err.code, 'SISO_CYCLE');
  // Caught at the first claimed event deeper than the gate count.
  assertEqual(err.depth, s.gates.size + 1);
});

test('Stream: longer indirect cycle a → b → c → a throws', () => {
  const mk = (sig, out) => new (class extends Gate {
    constructor() { super(sig); }
    transform() { return new Event(out, {}); }
  })();
  const s = new Stream();
  s.register(mk('a', 'b'));
  s.register(mk('b', 'c'));
  s.register(mk('c', 'a'));
  assertThrows(() => s.emit(new Event('a', {})), 'Cycle');
});

test('Stream: same type in sibling branches is legal', () => {
  // Acyclicity is per causal path, not across the tree.
  const mk = (sig, out) => new (class extends Gate {
    constructor() { super(sig); }
    transform() { return out ? out.map(t => new Event(t, {})) : null; }
  })();
  const s = new Stream();
  s.register(mk('root', ['x', 'y']));
  s.register(mk('x', ['z']));
  s.register(mk('y', ['z']));
  s.register(mk('z', ['done']));
  s.emit(new Event('root', {}));
  const residue = s.sampleHere().residue;
  assertEqual(residue.length, 2, 'z ran on both branches');
  assert(residue.every(e => e.type === 'done'));
});

test('Stream: longest legal chain ends unclaimed at depth gates + 1', () => {
  // Every gate claimed once, then a final unclaimed event.
  // That is the deepest a legal chain can go — no throw.
  const mk = (sig, out) => new (class extends Gate {
    constructor() { super(sig); }
    transform() { return new Event(out, {}); }
  })();
  const s = new Stream();
  s.register(mk('a', 'b'));
  s.register(mk('b', 'c'));
  s.register(mk('c', 'answer'));
  s.emit(new Event('a', {}));
  assertEqual(s.sampleHere().residue[0].type, 'answer');
});

test('Stream: a propagation that throws commits no residue', () => {
  const mk = (sig, out) => new (class extends Gate {
    constructor() { super(sig); }
    transform() { return out.map(t => new Event(t, {})); }
  })();
  const s = new Stream();
  s.register(mk('root', ['leftover', 'a']));
  s.register(mk('a', ['b']));
  s.register(mk('b', ['a']));
  s.emit(new Event('earlier', {}));            // settles normally
  assertThrows(() => s.emit(new Event('root', {})), 'Cycle');
  const residue = s.sampleHere().residue;
  assertEqual(residue.length, 1, 'only the earlier residue remains');
  assertEqual(residue[0].type, 'earlier');
});

test('Stream: stream remains usable after a throw', () => {
  class Echo extends Gate {
    constructor() { super('echo'); }
    transform() { return new Event('echo', {}); }
  }
  const s = new Stream();
  s.register(new Echo());
  assertThrows(() => s.emit(new Event('echo', {})), 'Self-loop');
  s.emit(new Event('fine', {}));
  assertEqual(s.sampleHere().residue.length, 1);
});

test('Stream: a gate that throws rejects with SISO_GATE_THREW, ids and cause', () => {
  const original = new TypeError('bad input');
  class Fwd extends Gate {
    constructor() { super('a'); }
    transform() { return new Event('b', {}); }
  }
  class Boom extends Gate {
    constructor() { super('b'); }
    transform() { throw original; }
  }
  const s = new Stream();
  s.register(new Fwd());
  s.register(new Boom());
  let err = null;
  try { s.emit(new Event('a', {})); } catch (e) { err = e; }
  assert(err !== null, 'emit threw');
  assertEqual(err.code, 'SISO_GATE_THREW');
  assertEqual(err.eventType, 'b', 'the event the gate was given');
  assertEqual(err.eventId, 2, 'the delivery that threw');
  assertEqual(err.causeId, 1, 'the delivery that caused it');
  assert(err.cause === original, 'the gate\'s own error is kept as cause');
  assert(err.message.includes('bad input'), 'message carries the original');
});

test('Stream: a gate that throws commits no residue; stream stays usable', () => {
  class Split extends Gate {
    constructor() { super('root'); }
    transform() { return [new Event('leftover', {}), new Event('b', {})]; }
  }
  class Boom extends Gate {
    constructor() { super('b'); }
    transform() { throw new Error('boom'); }
  }
  const s = new Stream();
  s.register(new Split());
  s.register(new Boom());
  assertThrows(() => s.emit(new Event('root', {})), 'boom');
  assertEqual(s.sampleHere().residue.length, 0, 'the queued sibling is not committed');
  s.emit(new Event('fine', {}));
  assertEqual(s.sampleHere().residue.length, 1);
});

test('Stream: a gate that throws a non-Error is wrapped the same way', () => {
  class Boom extends Gate {
    constructor() { super('x'); }
    transform() { throw 'plain string'; }
  }
  const s = new Stream();
  s.register(new Boom());
  let err = null;
  try { s.emit(new Event('x', {})); } catch (e) { err = e; }
  assert(err instanceof Error, 'a real Error leaves emit');
  assertEqual(err.code, 'SISO_GATE_THREW');
  assertEqual(err.cause, 'plain string');
  assertEqual(err.causeId, null, 'an external entry has no cause');
});

// ── Stream: take residue ───────────────────────

test('Stream: takeResidue returns the residue and removes it', () => {
  const s = new Stream();
  s.emit(new Event('x', { v: 1 }));
  s.emit(new Event('y', { v: 2 }));
  const taken = s.takeResidue();
  assertEqual(taken.length, 2);
  assertEqual(taken[0].type, 'x');
  assertEqual(taken[1].data.v, 2);
  assertEqual(s.sampleHere().residue.length, 0, 'stream emptied');
});

test('Stream: residue after a take holds only new arrivals', () => {
  const s = new Stream();
  s.emit(new Event('old', {}));
  s.takeResidue();
  s.emit(new Event('new', {}));
  const residue = s.sampleHere().residue;
  assertEqual(residue.length, 1);
  assertEqual(residue[0].type, 'new');
});

test('Stream: takeResidue on an empty stream returns []', () => {
  const s = new Stream();
  const taken = s.takeResidue();
  assert(Array.isArray(taken) && taken.length === 0);
});

test('Stream: sampling does not take', () => {
  const s = new Stream();
  s.emit(new Event('x', {}));
  s.sampleHere();
  s.sampleHere();
  assertEqual(s.takeResidue().length, 1);
});

// ── Stream: sampleHere ─────────────────────────

test('Stream: sampleHere returns copy of residue', () => {
  const s = new Stream();
  s.emit(new Event('x', {}));
  const sample = s.sampleHere();
  sample.residue.pop();
  assertEqual(s.sampleHere().residue.length, 1, 'original not affected');
});

test('Stream: eventCount tracks all emits including re-emits', () => {
  class Echo extends Gate {
    constructor() { super('echo'); }
    transform(event) {
      return new Event('out', {});
    }
  }
  const s = new Stream();
  s.register(new Echo());
  s.emit(new Event('echo', {}));
  assertEqual(s.sampleHere().eventCount, 2);
});

test('Stream: gateCount in sampleHere', () => {
  const s = new Stream();
  s.register(new Gate('a'));
  s.register(new Gate('b'));
  s.register(new Gate('c'));
  assertEqual(s.sampleHere().gateCount, 3);
});

test('Stream: residue accumulates across emits', () => {
  const s = new Stream();
  s.emit(new Event('x', {}));
  s.emit(new Event('y', {}));
  assertEqual(s.sampleHere().residue.length, 2);
});

// ── Stream: constructor options ────────────────

test('Stream: no-arg constructor still works', () => {
  const s = new Stream();
  assertEqual(s.log, null);
  assertEqual(s.streamId, null);
  assertEqual(s.parentStreamId, null);
});

test('Stream: constructor accepts log', () => {
  const fakeLog = { nextStreamId: () => 42 };
  const s = new Stream({ log: fakeLog });
  assertEqual(s.log, fakeLog);
  assertEqual(s.streamId, 42);
});

const exitCode = report('core');
process.exit(exitCode);
