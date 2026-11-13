/**
 * events/io.mjs — the events that cross the client's boundary.
 *
 * Mirrors src/Events/Io.php on the server. Client gates never call
 * fetch or touch the DOM. When a gate needs the server it returns an
 * `api_call` event; no gate claims it, so it settles as residue, and
 * the driver (boundary/driver.mjs) takes it, performs the request and
 * enters the reply event the request names, carrying `carry` plus the
 * HTTP result:
 *
 *   api_call  {method, path, body, reply, carry}
 *   reply     carry + {http: {status, contentType, json, error}}
 *             status 0 and error set when the request never completed
 *
 * Everything else a gate leaves for the boundary is a render
 * instruction (`ui_*`, drawn by ui/*.js) or the client session
 * (`session`, kept by the driver and put back on the next entry).
 */
import { Event } from '../../../siso/js/code/core/index.mjs';

/** A request to the game's REST API (paths are relative to ./api). */
export function api(method, path, body, reply, carry = {}) {
    return Event('api_call', { method, path, body, reply, carry });
}

/** The client session: what the next entry event carries forward. */
export function session(gameId, playerName, busy) {
    return Event('session', { gameId, playerName, busy });
}

/** A render instruction for the boundary. */
export function ui(type, data) {
    return Event(type, data);
}
