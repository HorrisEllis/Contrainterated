/*
core/gate.mjs — Gate, a shape that recognises one type of event and
transforms it into others.

Contract js-core-api GATE (core 13). Port of lua/code/core/gate.lua.

  import { Gate } from "./gate.mjs";
  Gate(signature, transform, shape) → a frozen { signature, transform, shape }

`signature` is the unique key the stream routes by. `transform` is a
pure function the stream calls with the event as its only argument (and
no receiver: `this` is undefined, so the gate is handed nothing but the
event). It returns:
  undefined or null  consumed, no follow-up
  an Event           one follow-up
  an array of Events own keys exactly the indices 0..length-1, each an Event
Anything else (false, 0, "", a number, a string, a non-Event object, a
Promise — an async transform —, a sparse array, an array with a named
key) rejects the propagation with SISO_NOT_EVENT. With no transform
(undefined), the gate consumes and returns nothing: one frozen no-op
function every such gate shares. `shape` is an optional declaration for
tooling ({ emits: [types…], rejects: [types…] }); the stream never reads
it. The three values are held as given; only the gate object is frozen.
*/

const noTransform = () => undefined;
Object.freeze(noTransform);

export function Gate(signature, transform, shape) {
  return Object.freeze({
    signature,
    transform: transform === undefined ? noTransform : transform,
    shape,
  });
}

Object.freeze(Gate.prototype);
Object.freeze(Gate);

export default Gate;
