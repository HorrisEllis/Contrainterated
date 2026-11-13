/**
 * Per-gate test helper for the client gates (public/js/gates).
 *
 * gate('signature') returns the project's real gate, built from
 * public/js/gates/index.mjs — never a copy. run(gate, data) registers
 * that gate alone on a fresh core 13 stream, enters one event of its
 * signature and takes the residue: only the gate under test runs, and
 * the real core judges what it returned (a throw, a self-loop or a
 * non-event return rejects the propagation and fails the case). The
 * whole output comes back as [{type, data}] — the core's frozen data, not a
 * JSON copy, so undefined or NaN fail same() instead of vanishing.
 *
 * test(name, fn) wraps the core harness's test so the census can
 * record each case's output shape (SISO_SHAPES), as the PHP helper
 * does. Cases named "rejected …" are rejections.
 */
import { appendFileSync } from 'node:fs';
import { basename } from 'node:path';
import { Event, Stream } from '../../siso/js/code/core/index.mjs';
import { data } from '../../siso/js/code/kit/data.mjs';
import * as H from '../../siso/js/code/harness.mjs';
import { gate as projectGate } from '../../public/js/gates/index.mjs';

let current = '';

export function gate(signature) {
    return projectGate(signature);
}

export function run(g, input) {
    const stream = new Stream();
    stream.register(g);
    stream.emit(Event(g.signature, input));
    // the core's own frozen data, compared through the kit's canonical JSON:
    // undefined, NaN and anything not plain JSON fail same() instead of vanishing
    const out = stream.takeResidue().map(e => ({ type: e.type, data: e.data }));
    const shapes = process.env.SISO_SHAPES;
    if (shapes) {
        appendFileSync(shapes, JSON.stringify({
            file: basename(process.argv[1] ?? ''),
            case: current,
            types: [...new Set(out.map(shapeOf))],
        }) + '\n');
    }
    return out;
}

/** One output event's shape: its type; for api_call also method, path pattern and reply. */
export function shapeOf(e) {
    if (e.type === 'api_call') {
        const path = String(e.data.path).replace(/\/game\/[^/]+\//, '/game/{id}/');
        return `api_call ${e.data.method} ${path}→${e.data.reply}`;
    }
    return e.type;
}

export function test(name, fn) {
    current = name;
    H.test(name, fn);
}

/** Deep equality through the kit's canonical JSON (key order free). */
export function same(actual, expected, label = '') {
    const [a, ea] = data.encode(actual);
    const [e, ee] = data.encode(expected);
    if (ea || ee) {
        H.fail(`${label} not encodable: ${ea ?? ee}`);
    }
    if (a !== e) {
        H.fail(`${label ? label + ': ' : ''}expected ${e}\n       got ${a}`);
    }
}

/** An HTTP result as the boundary hands it back. */
export function http(status, json, contentType = 'application/json') {
    return { status, contentType, json, error: null };
}

export function networkError(message) {
    return { status: 0, contentType: '', json: null, error: message };
}

export const finish = H.finish;
export const assert = H.assert;
