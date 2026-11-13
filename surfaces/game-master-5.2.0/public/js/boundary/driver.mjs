/**
 * boundary/driver.mjs — the client's boundary between the stream and
 * the browser. Mirrors src/Boundary/Driver.php.
 *
 * enter(event) runs one propagation (core 13's entry door), then takes
 * the residue (the take door) and does what it says:
 *   api_call  performed by `request` (fetch); when it answers, the reply
 *             event the call names is entered, carrying the call's
 *             carry plus {http}
 *   session   kept, and handed back by session() for the next entry
 *   anything  else is a render instruction, given to `render`
 *
 * The driver decides nothing about the game or the page: every
 * decision (what to ask, what to show, when to ignore input) is a gate
 * in public/js/gates. A rejected propagation (a gate threw, looped, or
 * returned something that is not an event) commits nothing, so a busy
 * flag it would have cleared is still set: the driver reports it to
 * `onRejection` (logging) and enters `client_rejected {code, message,
 * entryType, session}`, whose gate frees the page and shows the error.
 * If that propagation is rejected too, it is only reported.
 *
 * Boundary state (conformance ledger L2): the session — the game id, the
 * player's name and whether a request is in flight — is the one value
 * the client keeps between propagations. It is written only from a
 * `session` event a gate returned, and read only to build the next
 * entry event (core principle: the outside takes state and enters it
 * again with the next question). Nothing else is kept.
 */
import { Event, Stream } from '../../../siso/js/code/core/index.mjs';

export function createDriver({ gates, request, render, onRejection }) {
    const stream = new Stream();
    for (const g of gates) {
        stream.register(g);
    }
    let session = { gameId: null, playerName: '', busy: false };

    function enter(event) {
        try {
            stream.emit(event);
        } catch (rejection) {
            onRejection(rejection, event);
            if (event.type !== 'client_rejected') {
                enter(Event('client_rejected', {
                    code: typeof rejection?.code === 'string' ? rejection.code : 'SISO_REJECTED',
                    message: typeof rejection?.message === 'string' ? rejection.message : '',
                    entryType: event.type,
                    session,
                }));
            }
            return;
        }
        const calls = [];
        for (const taken of stream.takeResidue()) {
            if (taken.type === 'api_call') {
                calls.push(taken);
            } else if (taken.type === 'session') {
                session = taken.data;
            } else {
                render(taken);
            }
        }
        for (const call of calls) {
            serve(call);
        }
    }

    async function serve(call) {
        const { method, path, body, reply, carry } = call.data;
        const http = await request(method, path, body);
        enter(Event(reply, { ...carry, http }));
    }

    return Object.freeze({
        enter,
        session: () => session,
    });
}
