<?php
/*
core/event.php — Siso\Core\Event, a datum flowing through the stream.

Contract core-api EVENT / READ-ONLY / STATELESS THROUGH THE EVENT
(core 13.0.0, PHP instance). Port of lua/code/core/event.lua.

  require_once __DIR__ . '/event.php';
  use Siso\Core\Event;
  new Event($type, $data)  → a read-only event with exactly `type` and
                             `data` (data defaults to [])
  Event::is($v)            → $v instanceof Event

An event is a type and data, nothing else. Extra arguments attach
nothing (PHP drops them). Created, never modified by delivery: the
stream's delivery metadata (id, cause, depth) rides beside the event in
its queue, never on it.

Read-only from construction (core 13), by the language: both properties
are `public readonly`, and arrays are values, so assignment to the event
or anything in its data, taking a reference into it and unsetting a
property all raise \Error wherever the event was built. A dynamic
property is refused by __set / __unset with the contract's "read-only:"
message. Lua's frozen proxies, its `rawset` concession (R3) and the
freeze's private key do not exist here (core-api SUBSTRATE).

The data is taken as it stands (Freeze::take): it is rebuilt without the
PHP reference slots the caller may hold into it, and checked to be plain
data (null, bool, int, float, string, array, an Event, an enum case) at
every depth, iteratively; anything else is refused with
\InvalidArgumentException("Event: data at <path> is not plain data (<type>)").
Events and enum cases inside the data keep their identity.

unserialize() builds an Event only through __unserialize, which validates
exactly as the constructor does.

Stateless through the event: this class has no static property and no
method with `static` variables; nothing in it is written after load.
Final, so Event::is is exact: an array or object that looks like an
event is not one.
*/
declare(strict_types=1);

namespace Siso\Core;

require_once __DIR__ . '/freeze.php';

final class Event
{
    public readonly string $type;
    public readonly array $data;

    public function __construct(string $type, ?array $data = null)
    {
        $this->type = $type;
        $this->data = $data === null ? [] : Freeze::take($data);
    }

    public static function is(mixed $v): bool
    {
        return $v instanceof self;
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \Error("read-only: an event and its data cannot be assigned (field '" . $name . "')");
    }

    public function __unset(string $name): void
    {
        throw new \Error("read-only: an event and its data cannot be assigned (field '" . $name . "')");
    }

    public function __serialize(): array
    {
        return ['type' => $this->type, 'data' => $this->data];
    }

    public function __unserialize(array $a): void
    {
        $keys = array_map('strval', array_keys($a));
        sort($keys, SORT_STRING);
        if ($keys !== ['data', 'type'] || !is_string($a['type']) || !is_array($a['data'])) {
            throw new \InvalidArgumentException('Event: a serialized event holds exactly a string type and an array data');
        }
        $this->type = $a['type'];
        $this->data = Freeze::take($a['data']);
    }
}
