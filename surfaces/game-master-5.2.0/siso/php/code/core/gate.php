<?php
/*
core/gate.php — Siso\Core\Gate, a shape that recognises one type of event
and transforms it into others.

Contract core-api GATE (PHP instance). Port of lua/code/core/gate.lua.

  require_once __DIR__ . '/gate.php';
  use Siso\Core\Gate;
  new Gate($signature, $transform, $shape) → exactly `signature`,
                                             `transform` and `shape`

`signature` is the unique key the stream routes by. `transform` is a
pure function the stream calls as `($gate->transform)($event)`, with the
event as its only argument, kept as \Closure::fromCallable gives it (a
Closure is kept as itself). It returns null (or nothing), an Event, or a
list of Events (array_is_list; [] is consumed). Anything else rejects the
propagation with SISO_NOT_EVENT. With no transform the gate consumes and
returns nothing. `shape` is an optional declaration for tooling
(['emits' => [...], 'rejects' => [...]]); the stream never reads it.

No static state: no static property, no method with `static` variables.
*/
declare(strict_types=1);

namespace Siso\Core;

final class Gate
{
    public readonly string $signature;
    public readonly \Closure $transform;
    public readonly ?array $shape;

    public function __construct(string $signature, ?callable $transform = null, ?array $shape = null)
    {
        $this->signature = $signature;
        $this->transform = $transform === null
            ? static fn (mixed ...$ignored): mixed => null
            : \Closure::fromCallable($transform);
        $this->shape = $shape;
    }
}
