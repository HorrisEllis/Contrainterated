/**
 * TemplatesRequestGate — the page asks for the world list.
 *
 * Signature: app_started {}            (page load, and the retry button)
 * Emits:     api_call GET /templates → templates_replied
 */
import { api } from '../events/io.mjs';

export const signature = 'app_started';
export const emits = ['api_call'];

export function transform(event) {
    return api('GET', '/templates', null, 'templates_replied', {});
}
