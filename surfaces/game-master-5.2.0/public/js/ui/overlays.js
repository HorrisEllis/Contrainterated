/**
 * ui/overlays.js — the processing spinner and the connection-error overlay.
 * Boundary (DOM): draws ui_busy's spinner and ui_error_overlay; the
 * retry button is handed to onRetry as input.
 */

let _processing, _error, _errorDetails;

export function init(processingEl, errorEl, errorDetailsEl, retryBtn, onRetry) {
    _processing   = processingEl;
    _error        = errorEl;
    _errorDetails = errorDetailsEl;
    retryBtn.addEventListener('click', () => onRetry());
}

export function processing(active) {
    _processing.classList.toggle('active', active);
}

/** ui_error_overlay {visible, endpoint, code, message} */
export function errorOverlay({ visible, endpoint, code, message }) {
    if (!visible) {
        _error.classList.add('hidden');
        return;
    }
    const ts = new Date().toISOString().replace('T', ' ').substring(0, 19);   // display only
    _errorDetails.replaceChildren(
        `ERROR: ${code}`, document.createElement('br'),
        `ENDPOINT: ${endpoint}`, document.createElement('br'),
        `DETAILS: ${message}`, document.createElement('br'),
        `TIMESTAMP: ${ts}`,
    );
    _error.classList.remove('hidden');
}
