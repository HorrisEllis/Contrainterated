<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * TemplateCatalogGate — summarises the world templates for the
 * start screen.
 *
 * Signature: templates_loaded {reads: {catalog: {status, data: {id: template}}}}
 * Emits:     http_response 200 {success, data: [{id, name, theme, description}]}
 *            (an unreadable catalog lists nothing, as before)
 */
class TemplateCatalogGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('templates_loaded');
    }

    public function transform(Event $event): Event
    {
        $catalog = $event->data['reads']['catalog'] ?? [];
        $templates = ($catalog['status'] ?? '') === 'ok' && is_array($catalog['data'] ?? null)
            ? $catalog['data']
            : [];
        ksort($templates);

        $list = [];
        foreach ($templates as $id => $template) {
            if (!is_array($template)) {
                continue;
            }
            $list[] = [
                'id' => (string) $id,
                'name' => $template['name'] ?? (string) $id,
                'theme' => $template['theme'] ?? 'unknown',
                'description' => $template['description'] ?? '',
            ];
        }

        return Io::http(200, ['success' => true, 'data' => $list]);
    }
}
