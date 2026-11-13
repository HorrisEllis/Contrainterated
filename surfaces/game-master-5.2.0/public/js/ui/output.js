/**
 * ui/output.js — the narrative log and its scroll indicators.
 * Boundary (DOM): draws ui_log; decides nothing about the game.
 */

const SCROLL_BUFFER = 50;

let _output, _scrollTop, _scrollBottom;

export function init(outputEl, scrollTopEl, scrollBottomEl) {
    _output       = outputEl;
    _scrollTop    = scrollTopEl;
    _scrollBottom = scrollBottomEl;

    _output.addEventListener('scroll', updateIndicators);
    _scrollTop.addEventListener('click', () =>
        _output.scrollTo({ top: 0, behavior: 'smooth' }));
    _scrollBottom.addEventListener('click', () =>
        _output.scrollTo({ top: _output.scrollHeight, behavior: 'smooth' }));
}

/** ui_log {kind, text, inventory} */
export function append({ kind, text, inventory }) {
    const wasNearBottom = isNearBottom();

    const msg = document.createElement('div');
    msg.className = `message ${kind}`;

    if (kind === 'narrative') {
        const narrative = document.createElement('div');
        narrative.className = 'narrative';
        narrative.textContent = text;          // textContent = safe, no XSS
        msg.appendChild(narrative);

        if (inventory.length > 0) {
            const line = document.createElement('div');
            line.className = 'inventory-line';
            line.append('INVENTORY: ');
            const items = document.createElement('span');
            items.className = 'inventory-line-items';
            items.textContent = inventory.join(', ');
            line.appendChild(items);
            msg.appendChild(line);
        }
    } else {
        msg.textContent = text;
    }

    _output.appendChild(msg);
    if (wasNearBottom) {
        _output.scrollTo({ top: _output.scrollHeight, behavior: 'smooth' });
    }
    updateIndicators();
}

/**
 * After the log becomes visible (ui_screen): scroll to the end and refresh
 * the indicators. A ui_log drawn while the log was hidden measured
 * nothing, so this makes the result the same whichever of ui_screen and
 * ui_log is drawn first (the residue is a multiset: no order).
 */
export function settle() {
    _output.scrollTop = _output.scrollHeight;
    updateIndicators();
}

function isNearBottom() {
    return _output.scrollTop + _output.clientHeight >= _output.scrollHeight - 100;
}

function updateIndicators() {
    const top = _output.scrollTop;
    const h   = _output.scrollHeight;
    const ch  = _output.clientHeight;
    _scrollTop.classList.toggle('visible', top > SCROLL_BUFFER);
    _scrollBottom.classList.toggle('visible', top + ch < h - SCROLL_BUFFER);
}
