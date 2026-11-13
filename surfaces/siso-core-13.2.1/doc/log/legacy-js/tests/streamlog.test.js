import { test, assert, assertEqual, report } from './runner.js';
import { StreamLog } from '../code/src/StreamLog.js';
import { Stream } from '../code/src/Stream.js';
import { Event } from '../code/src/Event.js';
import { Gate } from '../code/src/Gate.js';

// ── Levels ─────────────────────────────────────

test('StreamLog: OFF records nothing', () => {
  const log = new StreamLog('OFF');
  log.record({ streamId: 1, eventType: 'x', gateClaimed: 'x', eventData: {} });
  assertEqual(log.sample().count, 0);
});

test('StreamLog: EVENTS records type and claimed', () => {
  const log = new StreamLog('EVENTS');
  log.record({ streamId: 1, eventType: 'foo', gateClaimed: 'foo', eventData: { big: true } });
  const entries = log.sample().entries;
  assertEqual(entries.length, 1);
  assertEqual(entries[0].type, 'foo');
  assertEqual(entries[0].claimed, 'foo');
  assert(entries[0].streamId === undefined, 'no streamId at EVENTS');
  assert(entries[0].data === undefined, 'no data at EVENTS');
});

test('StreamLog: EVENTS records null claimed for residue', () => {
  const log = new StreamLog('EVENTS');
  log.record({ streamId: 1, eventType: 'orphan', gateClaimed: null, eventData: {} });
  assertEqual(log.sample().entries[0].claimed, null);
});

test('StreamLog: DEEP includes streamId and parentStreamId', () => {
  const log = new StreamLog('DEEP');
  log.record({ streamId: 5, parentStreamId: 3, eventType: 'x', gateClaimed: 'x', eventData: {} });
  const e = log.sample().entries[0];
  assertEqual(e.streamId, 5);
  assertEqual(e.parentStreamId, 3);
  assert(e.data === undefined, 'no data at DEEP');
});

test('StreamLog: DEEP omits parentStreamId when null', () => {
  const log = new StreamLog('DEEP');
  log.record({ streamId: 1, parentStreamId: null, eventType: 'x', gateClaimed: null, eventData: {} });
  assert(!('parentStreamId' in log.sample().entries[0]));
});

test('StreamLog: DATA includes everything', () => {
  const log = new StreamLog('DATA');
  log.record({ streamId: 2, parentStreamId: 1, eventType: 'y', gateClaimed: 'y', eventData: { val: 42 } });
  const e = log.sample().entries[0];
  assertEqual(e.streamId, 2);
  assertEqual(e.parentStreamId, 1);
  assertEqual(e.data.val, 42);
});

// ── Sequencing ─────────────────────────────────

test('StreamLog: seq increments', () => {
  const log = new StreamLog('EVENTS');
  log.record({ streamId: 1, eventType: 'a', gateClaimed: null, eventData: {} });
  log.record({ streamId: 1, eventType: 'b', gateClaimed: null, eventData: {} });
  log.record({ streamId: 1, eventType: 'c', gateClaimed: null, eventData: {} });
  const entries = log.sample().entries;
  assertEqual(entries[0].seq, 0);
  assertEqual(entries[1].seq, 1);
  assertEqual(entries[2].seq, 2);
});

test('StreamLog: entries have timestamps', () => {
  const log = new StreamLog('EVENTS');
  const before = Date.now();
  log.record({ streamId: 1, eventType: 'x', gateClaimed: null, eventData: {} });
  const after = Date.now();
  const time = log.sample().entries[0].time;
  assert(time >= before && time <= after);
});

// ── Stream IDs ─────────────────────────────────

test('StreamLog: nextStreamId increments', () => {
  const log = new StreamLog('EVENTS');
  const a = log.nextStreamId();
  const b = log.nextStreamId();
  assertEqual(b, a + 1);
});

// ── sample and clear ───────────────────────────

test('StreamLog: sample returns copy', () => {
  const log = new StreamLog('EVENTS');
  log.record({ streamId: 1, eventType: 'x', gateClaimed: null, eventData: {} });
  const s = log.sample();
  s.entries.pop();
  assertEqual(log.sample().count, 1);
});

test('StreamLog: clear resets entries and seq', () => {
  const log = new StreamLog('EVENTS');
  log.record({ streamId: 1, eventType: 'x', gateClaimed: null, eventData: {} });
  log.record({ streamId: 1, eventType: 'y', gateClaimed: null, eventData: {} });
  log.clear();
  assertEqual(log.sample().count, 0);
  log.record({ streamId: 1, eventType: 'z', gateClaimed: null, eventData: {} });
  assertEqual(log.sample().entries[0].seq, 0, 'seq resets');
});

// ── Runtime level change ───────────────────────

test('StreamLog: level change takes effect immediately', () => {
  const log = new StreamLog('OFF');
  log.record({ streamId: 1, eventType: 'a', gateClaimed: null, eventData: {} });
  assertEqual(log.sample().count, 0);

  log.level = 'EVENTS';
  log.record({ streamId: 1, eventType: 'b', gateClaimed: null, eventData: {} });
  assertEqual(log.sample().count, 1);

  log.level = 'OFF';
  log.record({ streamId: 1, eventType: 'c', gateClaimed: null, eventData: {} });
  assertEqual(log.sample().count, 1);
});

test('StreamLog: upgrade level mid-run adds detail', () => {
  const log = new StreamLog('EVENTS');
  log.record({ streamId: 1, eventType: 'a', gateClaimed: null, eventData: { x: 1 } });

  log.level = 'DATA';
  log.record({ streamId: 1, eventType: 'b', gateClaimed: null, eventData: { x: 2 } });

  const entries = log.sample().entries;
  assert(entries[0].data === undefined, 'first entry has no data');
  assertEqual(entries[1].data.x, 2, 'second entry has data');
});

// ── Integration with Stream ────────────────────

test('StreamLog: Stream.emit records to log', () => {
  const log = new StreamLog('EVENTS');
  class Sink extends Gate {
    constructor() { super('sink'); }
    transform() { return null; }
  }
  const s = new Stream({ log });
  s.register(new Sink());
  s.emit(new Event('sink', {}));
  s.emit(new Event('orphan', {}));

  const entries = log.sample().entries;
  assertEqual(entries.length, 2);
  assertEqual(entries[0].claimed, 'sink');
  assertEqual(entries[1].claimed, null);
});

test('StreamLog: shared log across parent and sub-stream', () => {
  const log = new StreamLog('DEEP');

  const parent = new Stream({ log });
  const child = new Stream({ log, parentStreamId: parent.streamId });

  parent.emit(new Event('parent_event', {}));
  child.emit(new Event('child_event', {}));

  const entries = log.sample().entries;
  assertEqual(entries.length, 2);
  assertEqual(entries[0].streamId, parent.streamId);
  assertEqual(entries[1].streamId, child.streamId);
  assertEqual(entries[1].parentStreamId, parent.streamId);
});

test('StreamLog: returned events are recorded via log', () => {
  const log = new StreamLog('EVENTS');
  class Forwarder extends Gate {
    constructor() { super('forward'); }
    transform(event) {
      return new Event('forwarded', { from: event.data.origin });
    }
  }
  const s = new Stream({ log });
  s.register(new Forwarder());
  s.emit(new Event('forward', { origin: 'test' }));

  const entries = log.sample().entries;
  assertEqual(entries.length, 2);
  assertEqual(entries[0].type, 'forward');
  assertEqual(entries[0].claimed, 'forward');
  assertEqual(entries[1].type, 'forwarded');
  assertEqual(entries[1].claimed, null);
});

// ── Causal threads ─────────────────────────────

test('StreamLog: chain entries carry eventId and causeId', () => {
  const log = new StreamLog('EVENTS');
  const mk = (sig, out) => new (class extends Gate {
    constructor() { super(sig); }
    transform() { return out ? new Event(out, {}) : null; }
  })();
  const s = new Stream({ log });
  s.register(mk('x', 'y'));
  s.register(mk('y', 'z'));
  s.emit(new Event('x', {}));

  const e = log.sample().entries;
  assertEqual(e.length, 3);
  assertEqual(e[0].eventId, 1);
  assertEqual(e[0].causeId, null, 'external emit has no cause');
  assertEqual(e[1].eventId, 2);
  assertEqual(e[1].causeId, 1, 'y caused by x');
  assertEqual(e[2].eventId, 3);
  assertEqual(e[2].causeId, 2, 'z caused by y');
});

test('StreamLog: fan-out siblings share a cause', () => {
  const log = new StreamLog('EVENTS');
  class Root extends Gate {
    constructor() { super('root'); }
    transform() {
      return [new Event('left', {}), new Event('right', {})];
    }
  }
  const s = new Stream({ log });
  s.register(new Root());
  s.emit(new Event('root', {}));

  const e = log.sample().entries;
  assertEqual(e.length, 3);
  assertEqual(e[1].causeId, e[0].eventId, 'left caused by root');
  assertEqual(e[2].causeId, e[0].eventId, 'right caused by root');
});

test('StreamLog: separate external emits are separate roots', () => {
  const log = new StreamLog('EVENTS');
  const s = new Stream({ log });
  s.emit(new Event('a', {}));
  s.emit(new Event('b', {}));

  const e = log.sample().entries;
  assertEqual(e[0].causeId, null);
  assertEqual(e[1].causeId, null);
  assertEqual(e[1].eventId, e[0].eventId + 1, 'ids continue across emits');
});

test('StreamLog: causal chain reconstructible from a mixed tree', () => {
  // root fans out to two chains; entries interleave by
  // generation, but walking causeId links untangles each
  // thread mechanically — order means time, causeId means
  // cause.
  const log = new StreamLog('EVENTS');
  const mk = (sig, out) => new (class extends Gate {
    constructor() { super(sig); }
    transform() {
      return out ? out.map(t => new Event(t, {})) : null;
    }
  })();
  const s = new Stream({ log });
  s.register(mk('root', ['a', 'b']));
  s.register(mk('a', ['a1']));
  s.register(mk('b', ['b1']));
  s.register(mk('a1', ['a2']));
  s.emit(new Event('root', {}));

  const entries = log.sample().entries;
  // Find the a2 entry, walk to the root.
  const byId = new Map(entries.map(en => [en.eventId, en]));
  let cur = entries.find(en => en.type === 'a2');
  const thread = [];
  while (cur) {
    thread.unshift(cur.type);
    cur = cur.causeId != null ? byId.get(cur.causeId) : null;
  }
  assertEqual(thread.join(','), 'root,a,a1,a2');
});

// ── Acyclicity diagnostics ─────────────────────

test('StreamLog: entries carry no retries field', () => {
  const log = new StreamLog('EVENTS');
  const s = new Stream({ log });
  s.emit(new Event('plain', {}));
  const e = log.sample().entries;
  assertEqual(e.length, 1);
  assert(!('retries' in e[0]), 'no retries in core 11');
});

test('StreamLog: a cycle is readable from the causal chain', () => {
  // The stream's error can't name the loop by itself; an
  // attached log can, by walking causeId from the last entry.
  const log = new StreamLog('EVENTS');
  const mk = (sig, out) => new (class extends Gate {
    constructor() { super(sig); }
    transform() { return new Event(out, {}); }
  })();
  const s = new Stream({ log });
  s.register(mk('a', 'b'));
  s.register(mk('b', 'a'));
  let err = null;
  try { s.emit(new Event('a', {})); } catch (e) { err = e; }
  assertEqual(err.code, 'SISO_CYCLE');

  const entries = log.sample().entries;
  const byId = new Map(entries.map(en => [en.eventId, en]));
  let cur = byId.get(err.eventId);
  const thread = [];
  while (cur) {
    thread.unshift(cur.type);
    cur = cur.causeId != null ? byId.get(cur.causeId) : null;
  }
  assertEqual(thread.join(','), 'a,b,a');
});

test('StreamLog: self-loop error points at the producing entry', () => {
  const log = new StreamLog('EVENTS');
  class Bad extends Gate {
    constructor() { super('loop'); }
    transform() { return new Event('loop', {}); }
  }
  const s = new Stream({ log });
  s.register(new Bad());
  let err = null;
  try { s.emit(new Event('loop', {})); } catch (e) { err = e; }
  assertEqual(err.code, 'SISO_SELF_LOOP');
  const e = log.sample().entries;
  assertEqual(e.length, 1, 'the looping return is never delivered');
  assertEqual(e[0].eventId, err.eventId);
  assertEqual(e[0].claimed, 'loop');
});

test('StreamLog: gate-threw error points at the claiming entry', () => {
  const log = new StreamLog('EVENTS');
  class Fwd extends Gate {
    constructor() { super('a'); }
    transform() { return new Event('b', {}); }
  }
  class Boom extends Gate {
    constructor() { super('b'); }
    transform() { throw new Error('boom'); }
  }
  const s = new Stream({ log });
  s.register(new Fwd());
  s.register(new Boom());
  let err = null;
  try { s.emit(new Event('a', {})); } catch (e) { err = e; }
  assertEqual(err.code, 'SISO_GATE_THREW');
  const entry = log.sample().entries.find(x => x.eventId === err.eventId);
  assert(entry, 'the error names a logged delivery');
  assertEqual(entry.claimed, 'b');
  assertEqual(entry.causeId, err.causeId);
});

test('StreamLog: log presence does not change acyclicity outcome', () => {
  const mk = (sig, out) => new (class extends Gate {
    constructor() { super(sig); }
    transform() { return new Event(out, {}); }
  })();
  const codes = [null, new StreamLog('DATA')].map(log => {
    const s = new Stream({ log });
    s.register(mk('a', 'b'));
    s.register(mk('b', 'a'));
    try { s.emit(new Event('a', {})); return 'none'; }
    catch (e) { return `${e.code}@${e.depth}`; }
  });
  assertEqual(codes[0], codes[1]);
});

test('StreamLog: log absence changes nothing — ids from eventCount', () => {
  const mk = (sig, out) => new (class extends Gate {
    constructor() { super(sig); }
    transform() { return out ? new Event(out, {}) : null; }
  })();
  const bare = new Stream();
  bare.register(mk('x', 'y'));
  bare.emit(new Event('x', {}));
  const logged = new Stream({ log: new StreamLog('EVENTS') });
  logged.register(mk('x', 'y'));
  logged.emit(new Event('x', {}));

  assertEqual(bare.sampleHere().eventCount, logged.sampleHere().eventCount);
  assertEqual(bare.sampleHere().residue.length, logged.sampleHere().residue.length);
  assertEqual(bare.sampleHere().residue[0].type, logged.sampleHere().residue[0].type);
});

const exitCode = report('streamlog');
process.exit(exitCode);
