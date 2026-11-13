/**
 * Client scenarios — the whole client stream through its driver.
 *
 * The real gates (public/js/gates) and the real driver
 * (public/js/boundary/driver.mjs) run on SISO core 13.1.0; fetch is
 * replaced by a scripted server and the DOM by a recorder of render
 * instructions. Bodies follow the REST contract in SPEC. These are
 * chain tests: they never count as a gate's test.
 *
 * Run: node tests/client-scenarios.test.mjs
 */
import { Event } from '../siso/js/code/core/index.mjs';
import * as H from '../siso/js/code/harness.mjs';
import { Gate } from '../siso/js/code/core/index.mjs';
import { gates } from '../public/js/gates/index.mjs';
import { createDriver } from '../public/js/boundary/driver.mjs';

const json = (status, body) => ({ status, contentType: 'application/json', json: body, error: null });

/** A client whose server answers from `routes` ("METHOD path" → reply | (body) => reply). */
function client(routes, gateList = gates()) {
    const rendered = [];
    const calls = [];
    const pending = [];
    const rejections = [];
    const driver = createDriver({
        gates: gateList,
        request: (method, path, body) => {
            calls.push({ method, path, body });
            const route = routes[`${method} ${path}`];
            const reply = typeof route === 'function' ? route(body) : route;
            const p = Promise.resolve(reply ?? json(404, { success: false, error: 'no route' }));
            pending.push(p);
            return p;
        },
        render: e => rendered.push({ type: e.type, data: JSON.parse(JSON.stringify(e.data)) }),
        onRejection: r => rejections.push(r.code),
    });
    return {
        driver, rendered, calls, rejections,
        enter: (type, data) => driver.enter(Event(type, data)),
        settle: async () => {
            // let every in-flight request answer and its reply propagate
            for (let i = 0; i < 10; i++) {
                await Promise.all(pending.splice(0));
                await new Promise(r => setTimeout(r, 0));
            }
        },
        of: type => rendered.filter(e => e.type === type).map(e => e.data),
        clear: () => rendered.splice(0),
    };
}

const templates = json(200, { success: true, data: [
    { id: 'derelict_spaceship', name: 'Derelict Spaceship', theme: 'sci-fi horror', description: '' },
] });
const created = json(201, { success: true, message: 'Game created successfully', data: {
    gameId: 'game-1', worldTemplateId: 'derelict_spaceship', narrative: 'A cramped airlock.',
    currentZone: 'airlock', turnCount: 0,
    player: { name: 'Captain', inventory: [], stats: { health: 100 } },
    suggestedActions: ['Look around'],
} });
const turn = (n, inventory) => json(200, { success: true, error: null, meta: { tokensUsed: 500, turnTokens: 500 }, data: {
    narrative: `Turn ${n}.`, suggestedActions: ['Wait'], player: { name: 'Captain', inventory, stats: {} },
    turnCount: n, currentZone: 'airlock', lastSaved: '2026-10-20T10:00:00+00:00',
} });

const results = [];
function scenario(name, fn) {
    results.push([name, fn]);
}

scenario('page load fills the world picker and hides the error overlay', async () => {
    const c = client({ 'GET /templates': templates });
    c.enter('app_started', {});
    await c.settle();
    H.assertEqual(c.calls.length, 1, 'one request');
    H.assertEqual(JSON.stringify(c.of('ui_worlds')), JSON.stringify([{ options: [{ id: 'derelict_spaceship', name: 'Derelict Spaceship' }] }]));
    H.assertEqual(c.of('ui_error_overlay')[0].visible, false, 'overlay hidden');
});

scenario('a failed load shows the overlay; retry loads again', async () => {
    let up = false;
    const c = client({ 'GET /templates': () => up ? templates : { status: 0, contentType: '', json: null, error: 'Failed to fetch' } });
    c.enter('app_started', {});
    await c.settle();
    H.assertEqual(c.of('ui_error_overlay')[0].message, 'Failed to fetch');
    up = true;
    c.enter('app_started', {});
    await c.settle();
    H.assertEqual(c.of('ui_error_overlay')[1].visible, false, 'retry hides it');
    H.assertEqual(c.of('ui_worlds').length, 1);
});

scenario('start a game, play two turns: session carried, inventory shown, input freed', async () => {
    const c = client({
        'GET /templates': templates,
        'POST /game/new': created,
        'POST /game/game-1/action': body => turn(body.action === 'take chip' ? 2 : 1, body.action === 'take chip' ? ['data_chip'] : []),
    });
    c.enter('app_started', {});
    await c.settle();
    c.enter('start_submitted', { playerName: ' Captain ', worldTemplateId: 'derelict_spaceship', gameId: 'game-1', session: c.driver.session() });
    H.assertEqual(c.driver.session().busy, true, 'busy while the game is created');
    await c.settle();
    H.assertEqual(JSON.stringify(c.calls[1]), JSON.stringify({ method: 'POST', path: '/game/new', body: { worldTemplateId: 'derelict_spaceship', playerName: 'Captain', gameId: 'game-1' } }));
    H.assertEqual(JSON.stringify(c.driver.session()), JSON.stringify({ gameId: 'game-1', playerName: 'Captain', busy: false }));
    H.assertEqual(c.of('ui_screen')[0].screen, 'game');

    c.clear();
    c.enter('command_entered', { action: 'look', session: c.driver.session() });
    H.assertEqual(c.of('ui_log')[0].text, '> look', 'echo first');
    H.assertEqual(c.of('ui_busy')[0].busy, true);
    await c.settle();
    H.assertEqual(c.of('ui_log')[1].text, 'Turn 1.');
    H.assertEqual(c.of('ui_busy')[1].busy, false);
    H.assertEqual(c.of('ui_status')[1].text, 'CONNECTED');

    c.clear();
    c.enter('command_entered', { action: 'take chip', session: c.driver.session() });
    await c.settle();
    H.assertEqual(JSON.stringify(c.of('ui_log')[1].inventory), JSON.stringify(['data_chip']));
    H.assertEqual(c.of('ui_hud')[0].turn, 2);
    H.assertEqual(c.rejections.length, 0, 'no rejections');
});

scenario('a second command while a turn is in flight is ignored', async () => {
    const c = client({ 'POST /game/new': created, 'POST /game/game-1/action': turn(1, []) });
    c.enter('start_submitted', { playerName: 'A', worldTemplateId: 'derelict_spaceship', gameId: 'game-1', session: c.driver.session() });
    await c.settle();
    c.enter('command_entered', { action: 'one', session: c.driver.session() });
    c.enter('command_entered', { action: 'two', session: c.driver.session() });
    await c.settle();
    H.assertEqual(c.calls.filter(x => x.path.endsWith('/action')).length, 1, 'one turn sent');
    H.assertEqual(c.driver.session().busy, false, 'free again afterwards');
});

scenario('double start is ignored; a failed start restores the button and the session', async () => {
    const c = client({ 'POST /game/new': json(409, { success: false, error: "Game 'game-1' already exists" }) });
    c.enter('start_submitted', { playerName: 'A', worldTemplateId: 'x', gameId: 'game-1', session: c.driver.session() });
    c.enter('start_submitted', { playerName: 'A', worldTemplateId: 'x', gameId: 'game-1', session: c.driver.session() });
    await c.settle();
    H.assertEqual(c.calls.length, 1, 'one create sent');
    H.assertEqual(JSON.stringify(c.of('ui_start_button')), JSON.stringify([{ busy: true }, { busy: false }]));
    H.assertEqual(c.of('ui_log')[0].text, "ERROR: Game 'game-1' already exists");
    H.assertEqual(JSON.stringify(c.driver.session()), JSON.stringify({ gameId: null, playerName: '', busy: false }));
});

scenario('a failed turn shows ERROR and frees the input', async () => {
    const c = client({ 'POST /game/new': created, 'POST /game/game-1/action': json(422, { success: false, data: null, error: 'Action too long', meta: {} }) });
    c.enter('start_submitted', { playerName: 'A', worldTemplateId: 'x', gameId: 'game-1', session: c.driver.session() });
    await c.settle();
    c.clear();
    c.enter('command_entered', { action: 'x', session: c.driver.session() });
    await c.settle();
    H.assertEqual(c.of('ui_status')[1].text, 'ERROR');
    H.assertEqual(c.of('ui_log')[1].text, 'ERROR: Action too long');
    H.assertEqual(c.driver.session().busy, false);
});

scenario('hostile JSON in a turn reply is shown as text and never rejects', async () => {
    const hostile = json(200, { success: true, data: { narrative: { toString: 1 }, player: { inventory: [{ toString: 1 }] }, suggestedActions: 'x' } });
    const c = client({ 'POST /game/new': created, 'POST /game/game-1/action': hostile });
    c.enter('start_submitted', { playerName: 'A', worldTemplateId: 'x', gameId: 'game-1', session: c.driver.session() });
    await c.settle();
    c.enter('command_entered', { action: 'look', session: c.driver.session() });
    await c.settle();
    H.assertEqual(c.rejections.length, 0, 'no rejection');
    H.assertEqual(c.driver.session().busy, false, 'input freed');
});

scenario('a rejected reply propagation frees the page (client_rejected) instead of leaving it busy', async () => {
    // a deliberately broken command_replied gate, in place of the real one
    const broken = gates().filter(g => g.signature !== 'command_replied')
        .concat([Gate('command_replied', () => { throw new Error('boom'); })]);
    const c = client({ 'POST /game/new': created, 'POST /game/game-1/action': turn(1, []) }, broken);
    c.enter('start_submitted', { playerName: 'A', worldTemplateId: 'x', gameId: 'game-1', session: c.driver.session() });
    await c.settle();
    c.clear();
    c.enter('command_entered', { action: 'look', session: c.driver.session() });
    await c.settle();
    H.assertEqual(JSON.stringify(c.rejections), JSON.stringify(['SISO_GATE_THREW']));
    H.assertEqual(c.driver.session().busy, false, 'session freed');
    H.assertEqual(c.of('ui_busy').at(-1).busy, false, 'input freed');
    const overlay = c.of('ui_error_overlay').at(-1);
    H.assertEqual(overlay.code, 'SISO_GATE_THREW');
    H.assert(overlay.message.includes('(while handling command_replied)'), overlay.message);
    // and the next command goes through
    c.enter('command_entered', { action: 'again', session: c.driver.session() });
    H.assertEqual(c.calls.filter(x => x.path.endsWith('/action')).length, 2, 'second command sent');
});

scenario('a command before any game is ignored', async () => {
    const c = client({});
    c.enter('command_entered', { action: 'look', session: c.driver.session() });
    await c.settle();
    H.assertEqual(c.calls.length, 0);
    H.assertEqual(c.rendered.length, 0);
});

// The harness's tests are synchronous: run each scenario, then record it.
for (const [name, fn] of results) {
    let error = null;
    try {
        await fn();
    } catch (e) {
        error = e;
    }
    H.test(name, () => { if (error) throw error; });
}
H.finish('client-scenarios');
