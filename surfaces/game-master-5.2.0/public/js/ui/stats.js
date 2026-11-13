/**
 * ui/stats.js — the HUD, the suggested-action chips and the status light.
 * Boundary (DOM): draws ui_hud and ui_status; a chip click is handed to
 * onChip as input.
 */

let _zone, _turn, _player, _chips, _status, _onChip;

export function init(zoneEl, turnEl, playerEl, chipsEl, statusEl, onChip) {
    _zone   = zoneEl;
    _turn   = turnEl;
    _player = playerEl;
    _chips  = chipsEl;
    _status = statusEl;
    _onChip = onChip;
}

/** ui_hud {zone, turn, player, chips} — null leaves a field as it is. */
export function update({ zone, turn, player, chips }) {
    if (zone !== null) {
        _zone.textContent = zone;
    }
    if (turn !== null) {
        _turn.textContent = turn;
    }
    _player.textContent = player;
    if (chips !== null) {
        _chips.replaceChildren(...chips.map(action => {
            const btn = document.createElement('button');
            btn.className = 'btn btn-outline';
            btn.textContent = action;
            btn.addEventListener('click', () => _onChip(action));
            return btn;
        }));
    }
}

/** ui_status {text, kind} */
export function setStatus({ text, kind }) {
    _status.textContent = text;
    _status.className = 'status ' + kind;
}
