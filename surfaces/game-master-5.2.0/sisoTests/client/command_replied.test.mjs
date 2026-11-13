// TurnShownGate — command_replied → session + ui_busy + ui_status + ui_hud + ui_log
import { gate, run, test, same, http, networkError, finish } from './helper.mjs';

const g = gate('command_replied');
const inFlight = { gameId: 'game-1', playerName: 'Captain', busy: true };
const free = [
    { type: 'session', data: { gameId: 'game-1', playerName: 'Captain', busy: false } },
    { type: 'ui_busy', data: { busy: false, clearInput: false } },
];

// The 200 body POST /api/game/{id}/action sends (SPEC REST contract).
const turn = {
    success: true,
    data: {
        narrative: 'The terminal flickers to life...',
        suggestedActions: ['Read logs', 'Download data'],
        player: { name: 'Captain', inventory: ['flashlight', 'data_chip'], stats: { health: 100 } },
        turnCount: 3, currentZone: 'command_center', lastSaved: '2026-10-20T10:00:00+00:00',
    },
    error: null,
    meta: { tokensUsed: 2400, turnTokens: 2400 },
};

test('accepted: the narrative with the inventory, the HUD, CONNECTED, input freed', () => {
    same(run(g, { http: http(200, turn), session: inFlight }), [
        ...free,
        { type: 'ui_status', data: { text: 'CONNECTED', kind: 'connected' } },
        { type: 'ui_hud', data: { zone: 'COMMAND CENTER', turn: 3, player: 'CAPTAIN', chips: ['Read logs', 'Download data'] } },
        { type: 'ui_log', data: { kind: 'narrative', text: 'The terminal flickers to life...', inventory: ['flashlight', 'data_chip'] } },
    ]);
});

test('accepted: no suggestions and an empty inventory', () => {
    const body = { success: true, data: { narrative: 'Nothing happens.', player: { inventory: [] }, turnCount: 4, currentZone: 'airlock' } };
    same(run(g, { http: http(200, body), session: inFlight }), [
        ...free,
        { type: 'ui_status', data: { text: 'CONNECTED', kind: 'connected' } },
        { type: 'ui_hud', data: { zone: 'AIRLOCK', turn: 4, player: 'CAPTAIN', chips: null } },
        { type: 'ui_log', data: { kind: 'narrative', text: 'Nothing happens.', inventory: [] } },
    ]);
});

test('accepted: hostile JSON (objects where text is expected) is shown as empty text, never thrown on', () => {
    const body = { success: true, data: {
        narrative: { toString: 1 }, currentZone: { toString: 1 }, turnCount: '5',
        suggestedActions: [{ toString: 1 }, 'Go'], player: { inventory: [{ toString: 1 }, 7] },
    } };
    same(run(g, { http: http(200, body), session: inFlight }), [
        ...free,
        { type: 'ui_status', data: { text: 'CONNECTED', kind: 'connected' } },
        { type: 'ui_hud', data: { zone: null, turn: null, player: 'CAPTAIN', chips: ['', 'Go'] } },
        { type: 'ui_log', data: { kind: 'narrative', text: '', inventory: ['', '7'] } },
    ]);
});

const failed = message => [
    ...free,
    { type: 'ui_status', data: { text: 'ERROR', kind: 'error' } },
    { type: 'ui_log', data: { kind: 'error', text: 'ERROR: ' + message, inventory: [] } },
];

test('rejected (abort): an in-world failure (422, same body shape)', () => {
    same(run(g, { http: http(422, { success: false, data: null, error: 'Game not found: game-1', meta: {} }), session: inFlight }),
        failed('Game not found: game-1'));
});

test('rejected (abort): the stream settled without an answer (500)', () => {
    same(run(g, { http: http(500, { success: false, error: 'Processing failed - no result' }), session: inFlight }),
        failed('Processing failed - no result'));
});

test('rejected (abort): an error field that is not text', () => {
    same(run(g, { http: http(500, { success: false, error: { toString: 1 } }), session: inFlight }), failed('HTTP 500'));
});

test('rejected (abort): a JSON body that is not an object', () => {
    same(run(g, { http: http(200, 'ok'), session: inFlight }), failed('Unexpected response type (200)'));
});

test('rejected (abort): network failure', () => {
    same(run(g, { http: networkError('Failed to fetch'), session: inFlight }), failed('Failed to fetch'));
});

finish('command_replied');
