/**
 * app.js — the page's entry point: the client boundary, wired.
 *
 * The client runs on SISO core 13.1.0 (siso/js/code/core, vendored).
 * Every decision is a pure gate in ./gates; this file only connects the
 * page to the driver:
 *   input  (clicks, Enter, page load) → an entry event with the session
 *   output (residue)                  → ui/*.js draws it, http.mjs fetches
 */

import { gates }         from './gates/index.mjs';
import { createDriver }  from './boundary/driver.mjs';
import { request }       from './boundary/http.mjs';
import { Event }         from '../../siso/js/code/core/index.mjs';
import * as Output       from './ui/output.js';
import * as Stats        from './ui/stats.js';
import * as Overlays     from './ui/overlays.js';
import * as Screens      from './ui/screens.js';

const $ = id => document.getElementById(id);

const playerNameInput = $('player-name');
const worldSelect     = $('world-template');
const commandInput    = $('command-input');

// ── Render: one function per render instruction ─────
const renderers = {
    ui_worlds:        d => Screens.worlds(d),
    ui_start_button:  d => Screens.startButton(d),
    ui_screen:        d => { Screens.show(d); Output.settle(); },
    ui_busy:          d => { Screens.input(d); Overlays.processing(d.busy); },
    ui_hud:           d => Stats.update(d),
    ui_status:        d => Stats.setStatus(d),
    ui_log:           d => Output.append(d),
    ui_error_overlay: d => Overlays.errorOverlay(d),
};

function render(event) {
    const draw = renderers[event.type];
    if (draw) {
        draw(event.data);
    } else {
        console.warn('Unrendered residue (no renderer):', event.type, event.data);   // a stray (core K6)
    }
}

const driver = createDriver({
    gates: gates(),
    request,
    render,
    onRejection(rejection, entry) {      // logging only; client_rejected's gate decides what the page shows
        console.error('Client propagation rejected:', rejection.code, rejection.message, 'entry:', entry.type);
    },
});

// ── Input: one entry event per user action ──────────
const enter = (type, data) => driver.enter(Event(type, data));

function startGame() {
    enter('start_submitted', {
        playerName: playerNameInput.value,
        worldTemplateId: worldSelect.value,
        gameId: 'game-' + Date.now(),          // the boundary's clock, as data
        session: driver.session(),
    });
}

function sendCommand(action) {
    enter('command_entered', { action, session: driver.session() });
}

Output.init($('output'), $('scroll-top'), $('scroll-bottom'));
Stats.init($('stat-zone'), $('stat-turn'), $('stat-player'), $('action-chips'), $('status'), sendCommand);
Overlays.init($('processing-overlay'), $('error-overlay'), $('error-details'), $('error-retry-btn'),
    () => enter('app_started', {}));
Screens.init({
    startScreen: $('start-screen'), gameScreen: $('game-screen'), worldSelect,
    startBtn: $('start-btn'), commandInput, submitBtn: $('submit-btn'),
    screen: document.querySelector('.screen'),
});

$('start-btn').addEventListener('click', startGame);
playerNameInput.addEventListener('keypress', e => { if (e.key === 'Enter') startGame(); });
$('submit-btn').addEventListener('click', () => sendCommand(commandInput.value));
commandInput.addEventListener('keypress', e => { if (e.key === 'Enter') sendCommand(commandInput.value); });

// A module script runs after the document is parsed.
enter('app_started', {});
