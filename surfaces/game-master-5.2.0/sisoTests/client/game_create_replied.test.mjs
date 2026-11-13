// GameStartedGate — game_create_replied → session + ui_screen + ui_hud + ui_log
import { gate, run, test, same, http, networkError, finish } from './helper.mjs';

const g = gate('game_create_replied');
const previous = { gameId: null, playerName: '', busy: false };

// The 201 body POST /api/game/new sends (SPEC REST contract).
const created = {
    success: true,
    message: 'Game created successfully',
    data: {
        gameId: 'game-1', worldTemplateId: 'derelict_spaceship',
        narrative: 'A cramped airlock with flickering emergency lights.',
        currentZone: 'airlock', turnCount: 0,
        player: { name: 'Captain', inventory: ['flashlight'], stats: { health: 100 } },
        suggestedActions: ['Look around', 'Check suit'],
    },
};

test('accepted: the game screen, the HUD and the opening narrative', () => {
    same(run(g, { http: http(201, created), playerName: 'Captain', gameId: 'game-1', previous }), [
        { type: 'session', data: { gameId: 'game-1', playerName: 'Captain', busy: false } },
        { type: 'ui_screen', data: { screen: 'game' } },
        { type: 'ui_hud', data: { zone: 'AIRLOCK', turn: 0, player: 'CAPTAIN', chips: ['Look around', 'Check suit'] } },
        { type: 'ui_log', data: { kind: 'narrative', text: 'A cramped airlock with flickering emergency lights.', inventory: ['flashlight'] } },
    ]);
});

test('accepted: the server\'s game id wins; zone underscores become spaces', () => {
    const body = { success: true, data: { gameId: 'server-id', narrative: 'x', currentZone: 'pioneer_square', turnCount: 0, player: { inventory: [] } } };
    same(run(g, { http: http(201, body), playerName: 'Ann', gameId: 'game-9', previous }), [
        { type: 'session', data: { gameId: 'server-id', playerName: 'Ann', busy: false } },
        { type: 'ui_screen', data: { screen: 'game' } },
        { type: 'ui_hud', data: { zone: 'PIONEER SQUARE', turn: 0, player: 'ANN', chips: null } },
        { type: 'ui_log', data: { kind: 'narrative', text: 'x', inventory: [] } },
    ]);
});

test('accepted: a reply without a game id keeps the one asked for', () => {
    const body = { success: true, data: { narrative: 'y' } };
    same(run(g, { http: http(201, body), playerName: 'Ann', gameId: 'game-9', previous }), [
        { type: 'session', data: { gameId: 'game-9', playerName: 'Ann', busy: false } },
        { type: 'ui_screen', data: { screen: 'game' } },
        { type: 'ui_hud', data: { zone: null, turn: null, player: 'ANN', chips: null } },
        { type: 'ui_log', data: { kind: 'narrative', text: 'y', inventory: [] } },
    ]);
});

test('accepted: a game id that is not text keeps the one asked for', () => {
    const body = { success: true, data: { gameId: { toString: 1 }, narrative: 'z', player: 'nobody' } };
    same(run(g, { http: http(201, body), playerName: 'Ann', gameId: 'game-9', previous }), [
        { type: 'session', data: { gameId: 'game-9', playerName: 'Ann', busy: false } },
        { type: 'ui_screen', data: { screen: 'game' } },
        { type: 'ui_hud', data: { zone: null, turn: null, player: 'ANN', chips: null } },
        { type: 'ui_log', data: { kind: 'narrative', text: 'z', inventory: [] } },
    ]);
});

const failed = message => [
    { type: 'session', data: { gameId: null, playerName: '', busy: false } },
    { type: 'ui_start_button', data: { busy: false } },
    { type: 'ui_log', data: { kind: 'error', text: 'ERROR: ' + message, inventory: [] } },
];

test('rejected (abort): the game already exists (409)', () => {
    same(run(g, { http: http(409, { success: false, error: "Game 'game-1' already exists" }), playerName: 'A', gameId: 'game-1', previous }),
        failed("Game 'game-1' already exists"));
});

test('rejected (abort): validation failed (422)', () => {
    same(run(g, { http: http(422, { success: false, error: 'Validation failed', errors: { playerName: 'too long' } }), playerName: 'A', gameId: 'game-1', previous }),
        failed('Validation failed'));
});

test('rejected (abort): network failure', () => {
    same(run(g, { http: networkError('NetworkError when attempting to fetch resource.'), playerName: 'A', gameId: 'game-1', previous }),
        failed('NetworkError when attempting to fetch resource.'));
});

finish('game_create_replied');
