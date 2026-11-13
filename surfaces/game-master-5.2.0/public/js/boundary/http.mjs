/**
 * boundary/http.mjs — the REST API, as transport only.
 *
 * request(method, path, body) → {status, contentType, json, error}.
 * It never throws and decides nothing: reading the result (success,
 * error text) is pure/api.mjs, inside the gates. json is the parsed
 * body when the reply says it is JSON, else null; error is set when
 * the request did not complete or the JSON did not parse (status 0
 * when there was no reply at all).
 */
const API_BASE = './api';

export async function request(method, path, body = null) {
    const opts = { method, headers: { 'Content-Type': 'application/json' } };
    if (body !== null) {
        opts.body = JSON.stringify(body);
    }
    let res;
    try {
        res = await fetch(API_BASE + path, opts);
    } catch (err) {
        return { status: 0, contentType: '', json: null, error: String(err?.message ?? err) };
    }
    const contentType = res.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
        return { status: res.status, contentType, json: null, error: null };
    }
    try {
        return { status: res.status, contentType, json: await res.json(), error: null };
    } catch (err) {
        return { status: res.status, contentType, json: null, error: String(err?.message ?? err) };
    }
}
