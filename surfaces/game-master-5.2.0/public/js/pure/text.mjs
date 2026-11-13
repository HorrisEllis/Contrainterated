/**
 * pure/text.mjs — turning reply data into display text, without throwing.
 *
 * Reply bodies are JSON from the server, so any field can be any JSON
 * value. String(v) can throw on an object (an own `toString` that is not
 * a function), and a gate must not throw (principles 2.0, Rule 1), so
 * every field a client gate shows goes through text().
 *
 *   text(v, fallback = '')  a string as is; a finite number or a boolean
 *                           as String gives it; anything else (null,
 *                           objects, arrays) → fallback
 *   texts(v)                an array → text() of each member; else []
 */
export function text(v, fallback = '') {
    if (typeof v === 'string') {
        return v;
    }
    if ((typeof v === 'number' && Number.isFinite(v)) || typeof v === 'boolean') {
        return String(v);
    }
    return fallback;
}

export function texts(v) {
    return Array.isArray(v) ? v.map(m => text(m)) : [];
}
