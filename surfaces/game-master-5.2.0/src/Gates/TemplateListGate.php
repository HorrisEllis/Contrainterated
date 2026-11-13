<?php

namespace GameMaster\Gates;

use GameMaster\Events\Io;
use Siso\Core\Event;
use GameMaster\GameGate;

/**
 * TemplateListGate — a template listing was requested.
 *
 * Signature: templates_requested {}
 * Emits:     io_read {catalog: templates} → templates_loaded
 */
class TemplateListGate extends GameGate
{
    public function __construct()
    {
        parent::__construct('templates_requested');
    }

    public function transform(Event $event): Event
    {
        return Io::read(['catalog' => Io::templates()], 'templates_loaded');
    }
}
