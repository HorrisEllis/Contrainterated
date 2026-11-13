/**
 * pure/api.mjs — reading an API reply. Pure: no I/O, no state, never
 * throws (pure/text.mjs).
 *
 * apiResult(http) → {ok, data, error}, from the {status, contentType,
 * json, error} the boundary hands back. The rules are the ones the old
 * api/client.js applied inside fetch: a network or parse error is that
 * error; a non-JSON reply is "Unexpected response type (status)"; a
 * non-2xx status or success:false is the body's error, else "HTTP status";
 * otherwise data is body.data (or the whole body when it has none).
 */
import { text } from './text.mjs';

export function apiResult(http) {
    const status = text(http.status, '0');
    if (http.error !== null && http.error !== undefined) {
        return { ok: false, data: null, error: text(http.error, 'Request failed') };
    }
    const json = http.json;
    if (!text(http.contentType).includes('application/json') || json === null || typeof json !== 'object') {
        return { ok: false, data: null, error: `Unexpected response type (${status})` };
    }
    const okStatus = http.status >= 200 && http.status < 300;
    if (!okStatus || json.success !== true) {
        return { ok: false, data: null, error: text(json.error) || `HTTP ${status}` };
    }
    return { ok: true, data: json.data ?? json, error: null };
}
