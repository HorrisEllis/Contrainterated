<?php

namespace GameMaster;

use Siso\Core\Event;
use Siso\Core\Gate;

/**
 * GameGate — how this project writes a gate.
 *
 * Core 13 makes Siso\Core\Gate a final value: a signature and a
 * transform closure. Each gate here is a small class that names
 * its signature and implements transform(); gate() hands the core
 * that pair. The class exists so a gate can be read, documented
 * and tested as one unit; it is not a fourth primitive.
 *
 * Instances are read-only: the signature, and in one case
 * (ZonePreGenerationGate) a constructor-fixed number, are the only
 * properties, and nothing writes them after construction. A gate
 * keeps no state between calls (SISO Rule 1; ledger P1 in the core
 * spec: purity is the author's convention in PHP).
 */
abstract class GameGate
{
    public function __construct(public readonly string $signature)
    {
    }

    /** One event in; null, an Event or a list of Events out. Never throws. */
    abstract public function transform(Event $event);

    /** The core gate: this signature and this transform. */
    public function gate(): Gate
    {
        return new Gate($this->signature, $this->transform(...));
    }
}
