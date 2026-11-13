/**
 * ui/screens.js — the start screen, the world picker, the screen switch
 * and the command input.
 * Boundary (DOM): draws ui_worlds, ui_start_button, ui_screen and
 * ui_busy's input state; decides nothing about the game.
 */

let _startScreen, _gameScreen, _worldSelect, _startBtn, _commandInput, _submitBtn, _screen;

export function init(els) {
    ({ startScreen: _startScreen, gameScreen: _gameScreen, worldSelect: _worldSelect,
       startBtn: _startBtn, commandInput: _commandInput, submitBtn: _submitBtn, screen: _screen } = els);
}

/** ui_worlds {options: [{id, name}]} */
export function worlds({ options }) {
    _worldSelect.replaceChildren(...options.map(({ id, name }) => {
        const opt = document.createElement('option');
        opt.value = id;
        opt.textContent = name;
        return opt;
    }));
}

/** ui_start_button {busy} */
export function startButton({ busy }) {
    _startBtn.disabled = busy;
    _startBtn.textContent = busy ? '[ INITIALIZING... ]' : '[ INITIALIZE MISSION ]';
}

/** ui_screen {screen: 'game'} */
export function show({ screen }) {
    if (screen !== 'game') {
        return;
    }
    _startScreen.classList.add('hidden');
    _gameScreen.classList.remove('hidden');
    _screen.classList.add('glitch');
    setTimeout(() => _screen.classList.remove('glitch'), 500);
    _commandInput.focus();
}

/** ui_busy {busy, clearInput}: the input while a turn is in flight. */
export function input({ busy, clearInput }) {
    if (clearInput) {
        _commandInput.value = '';
    }
    _submitBtn.disabled = busy;
    _commandInput.disabled = busy;
    if (!busy) {
        _commandInput.focus();
    }
}
