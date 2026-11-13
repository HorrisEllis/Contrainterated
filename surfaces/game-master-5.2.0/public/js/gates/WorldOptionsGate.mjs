/**
 * WorldOptionsGate — the world list arrived: fill the picker.
 *
 * Signature: templates_replied {http}
 * Emits:     ui_worlds {options: [{id, name}]}, ui_error_overlay {visible: false}
 * Rejects:   (abort) the request failed → ui_error_overlay {visible: true,
 *            endpoint: './api/templates', code: 'API_ERROR', message}
 */
import { ui } from '../events/io.mjs';
import { apiResult } from '../pure/api.mjs';
import { text } from '../pure/text.mjs';

export const signature = 'templates_replied';
export const emits = ['ui_worlds', 'ui_error_overlay'];

const hidden = { visible: false, endpoint: null, code: null, message: null };

export function transform(event) {
    const r = apiResult(event.data.http);
    if (!r.ok) {
        return ui('ui_error_overlay', { visible: true, endpoint: './api/templates', code: 'API_ERROR', message: r.error });
    }
    const list = Array.isArray(r.data) ? r.data : (Array.isArray(r.data?.templates) ? r.data.templates : []);
    const options = list.map(t => {
        const id = text(t?.id);
        return { id, name: text(t?.name) || id };
    });
    return [ui('ui_worlds', { options }), ui('ui_error_overlay', hidden)];
}
