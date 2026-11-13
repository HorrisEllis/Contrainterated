<?php
/*
core/freeze.php — Siso\Core\Freeze, how the stream takes and copies data
(core 13.0.0, PHP instance). Port of lua/code/core/freeze.lua.

Contract core-api EVENT / READ-ONLY, STATELESS THROUGH THE EVENT,
IMMUTABLE SENTINELS and OWNERSHIP, and observer-seam RECORD data and THE
REJECTION.

In PHP the language makes an event read-only (`public readonly`
properties, arrays as values), so nothing here builds a proxy: Lua's
freeze(v), its view, its private key and its `rawset` concession have no
counterpart (core-api SUBSTRATE). What remains is taking data as it
stands and making the receiver's own copies:

  Freeze::take($data)          → the data rebuilt without PHP reference
                                 slots, checked to be plain data at every
                                 depth (Event's constructor and
                                 __unserialize call it)
  Freeze::copy($event, $memo)  → a fresh Event equal in value, with a
                                 fresh Event for every Event in its data,
                                 at every depth
  Freeze::copyData($d, $memo)  → $d with every Event in it, at any depth,
                                 replaced by a fresh copy
  Freeze::plain($rejection)    → observer-seam THE REJECTION: a plain
                                 array of the rejection's non-null public
                                 fields, copied at every depth

`$memo` is the caller's, one per owner (one per sample, one per observer):
an Event reached twice among that owner's copies is copied once; nothing
is shared with the original or with another owner's copies. It is keyed
by spl_object_id of originals the caller keeps alive while it copies.

Arrays are values, so an array without Events in it is never rebuilt by a
copy: sharing a value is not sharing state (nothing can be written into
it, and no \WeakMap can be keyed by it). A copy of an Event with no Event
in its data is `clone` of it (a fresh identity holding the same validated
values); one with Events inside is built by the constructor from the
rebuilt data.

Iterative, not recursive (core-api SUBSTRATE: Depth): every walk keeps an
explicit stack of frames, so no depth of data spends PHP's call stack;
memory is the substrate's remaining limit. The cost of each call grows
with what it takes or copies, never with history.

Cycles: an array can only be recursive through PHP references
(`$a['self'] = &$a`). take() follows reference slots by their
ReflectionReference id along the current path and refuses a recursive
array as not plain data; plain() turns a cycle (an object or a recursive
array met again inside its own copy) into null. Events are built
bottom-up and immutable, so ordinary code cannot make one that contains
itself; one forged by Reflection is refused by copy() with an exception
the stream turns into a rejection.

No static state: a final class of static functions, with no static
property and no `static` variable; nothing here is written after load.
*/
declare(strict_types=1);

namespace Siso\Core;

require_once __DIR__ . '/event.php';

final class Freeze
{
    private function __construct()
    {
    }

    /** "['a'][0]": the keys from the data's root */
    private static function pathOf(array $keys): string
    {
        $s = '';
        foreach ($keys as $k) {
            $s .= '[' . var_export($k, true) . ']';
        }
        return $s;
    }

    /*
    take($data) → $data rebuilt: every array a fresh value with no
    reference slot, scalars and null as they are, Events and enum cases
    kept by identity. Anything else is refused:
      \InvalidArgumentException("Event: data at <path> is not plain data (<get_debug_type>)")
    and an array that contains itself through a reference is refused the
    same way with "(recursive array)".
    */
    public static function take(array $data): array
    {
        // frames: source array, its keys, next position, output, key in parent, reference id
        $src = [$data];
        $keys = [array_keys($data)];
        $pos = [0];
        $out = [[]];
        $pk = [null];
        $rid = [null];
        $onPath = [];
        $t = 0;
        while (true) {
            $n = count($keys[$t]);
            $down = false;
            while ($pos[$t] < $n) {
                $k = $keys[$t][$pos[$t]];
                $pos[$t]++;
                $v = $src[$t][$k];
                if (is_array($v)) {
                    $id = \ReflectionReference::fromArrayElement($src[$t], $k)?->getId();
                    if ($id !== null) {
                        if (isset($onPath[$id])) {
                            throw self::refused($pk, $t, $k, 'recursive array');
                        }
                        $onPath[$id] = true;
                    }
                    $t++;
                    $src[$t] = $v;
                    $keys[$t] = array_keys($v);
                    $pos[$t] = 0;
                    $out[$t] = [];
                    $pk[$t] = $k;
                    $rid[$t] = $id;
                    $down = true;
                    break;
                }
                if ($v === null || is_scalar($v) || $v instanceof Event || $v instanceof \UnitEnum) {
                    $out[$t][$k] = $v;
                    continue;
                }
                throw self::refused($pk, $t, $k, get_debug_type($v));
            }
            if ($down) {
                continue;
            }
            $done = $out[$t];
            if ($t === 0) {
                return $done;
            }
            $key = $pk[$t];
            if ($rid[$t] !== null) {
                unset($onPath[$rid[$t]]);
            }
            unset($src[$t], $keys[$t], $pos[$t], $out[$t], $pk[$t], $rid[$t]);
            $t--;
            $out[$t][$key] = $done;
        }
    }

    private static function refused(array $pk, int $t, int|string $k, string $what): \InvalidArgumentException
    {
        $path = [];
        for ($i = 1; $i <= $t; $i++) {
            $path[] = $pk[$i];
        }
        $path[] = $k;
        return new \InvalidArgumentException('Event: data at ' . self::pathOf($path) . ' is not plain data (' . $what . ')');
    }

    /** a fresh copy of $event, fresh at every depth (one memo per owner) */
    public static function copy(Event $event, array &$memo): Event
    {
        return self::rebuild($event, $memo);
    }

    /** $data with every Event in it replaced by a fresh copy (one memo per owner) */
    public static function copyData(array $data, array &$memo): array
    {
        return self::rebuild($data, $memo);
    }

    /*
    rebuild($root, $memo): $root is an Event or an array. A post-order
    walk over arrays and Events with an explicit stack. An array frame's
    output starts as its source (a shared value) and is written only where
    a child changed; an Event frame's one child is its data, and when the
    data is done the Event is cloned (data unchanged) or built anew (data
    rebuilt). A copied Event always counts as changed, so every array on a
    path to an Event is rebuilt and every other array is shared as a value.
    */
    private static function rebuild(Event|array $root, array &$memo): Event|array
    {
        $isEvent = [];
        $src = [];
        $keys = [];
        $pos = [];
        $out = [];
        $changed = [];
        $pk = [];
        $onPath = [];
        $t = -1;
        $next = $root;
        $nextKey = null;
        $res = null;
        $resChanged = false;
        $resKey = null;
        while (true) {
            if ($next !== null) {
                $v = $next;
                $k = $nextKey;
                $next = null;
                if ($v instanceof Event) {
                    $id = spl_object_id($v);
                    if (isset($memo[$id])) {
                        $res = $memo[$id];
                        $resChanged = true;
                        $resKey = $k;
                    } else {
                        if (isset($onPath[$id])) {
                            throw new \UnexpectedValueException('Freeze: an Event that contains itself cannot be copied');
                        }
                        $onPath[$id] = true;
                        $t++;
                        $isEvent[$t] = true;
                        $src[$t] = $v;
                        $pk[$t] = $k;
                        $next = $v->data;
                        $nextKey = null;
                        continue;
                    }
                } else {
                    $t++;
                    $isEvent[$t] = false;
                    $src[$t] = $v;
                    $keys[$t] = array_keys($v);
                    $pos[$t] = 0;
                    $out[$t] = null;
                    $changed[$t] = false;
                    $pk[$t] = $k;
                    continue;
                }
            } else {
                // the top frame is an array frame: find its next child to descend into
                $n = count($keys[$t]);
                while ($pos[$t] < $n) {
                    $k = $keys[$t][$pos[$t]];
                    $pos[$t]++;
                    $v = $src[$t][$k];
                    if ($v instanceof Event || (is_array($v) && $v !== [])) {
                        $next = $v;
                        $nextKey = $k;
                        break;
                    }
                }
                if ($next !== null) {
                    continue;
                }
                $resChanged = $changed[$t];
                $res = $resChanged ? $out[$t] : $src[$t];
                $resKey = $pk[$t];
                unset($isEvent[$t], $src[$t], $keys[$t], $pos[$t], $out[$t], $changed[$t], $pk[$t]);
                $t--;
            }
            // deliver $res (keyed $resKey) to the frame below
            while (true) {
                if ($t < 0) {
                    return $res;
                }
                if ($isEvent[$t]) {
                    $e = $src[$t];
                    $copy = $resChanged ? new Event($e->type, $res) : clone $e;
                    $id = spl_object_id($e);
                    $memo[$id] = $copy;
                    unset($onPath[$id]);
                    $resKey = $pk[$t];
                    unset($isEvent[$t], $src[$t], $pk[$t]);
                    $t--;
                    $res = $copy;
                    $resChanged = true;
                    continue;
                }
                if ($resChanged) {
                    if (!$changed[$t]) {
                        $out[$t] = $src[$t];
                        $changed[$t] = true;
                    }
                    $out[$t][$resKey] = $res;
                }
                break;
            }
        }
    }

    /*
    plain($rejection) → observer-seam THE REJECTION: an array with a key
    for each public field of $rejection that is not null, each value
    copied at every depth: arrays rebuilt (no reference slot), Events
    copied fresh (a memo of the copy's own), enum cases kept, any other
    object copied as an array of its public properties (read from this
    class's scope, so only public ones; no code of the object's runs), and
    an object or a recursive array met again inside its own copy, a cycle,
    becomes null. No object in the result but a fresh Event or an enum
    case.
    */
    public static function plain(object $rejection): array
    {
        $fields = [];
        foreach (get_object_vars($rejection) as $k => $v) {
            if ($v !== null) {
                $fields[$k] = $v;
            }
        }
        $memo = [];
        // frames: source array, its keys, next position, output, key in parent, path mark
        $src = [$fields];
        $keys = [array_keys($fields)];
        $pos = [0];
        $out = [[]];
        $pk = [null];
        $mark = [null];
        $onPath = [];
        $t = 0;
        while (true) {
            $n = count($keys[$t]);
            $down = false;
            while ($pos[$t] < $n) {
                $k = $keys[$t][$pos[$t]];
                $pos[$t]++;
                $v = $src[$t][$k];
                $children = null;
                $m = null;
                if (is_array($v)) {
                    $id = \ReflectionReference::fromArrayElement($src[$t], $k)?->getId();
                    if ($id !== null) {
                        $m = 'r' . $id;
                    }
                    $children = $v;
                } elseif ($v instanceof Event) {
                    $out[$t][$k] = self::copy($v, $memo);
                    continue;
                } elseif (is_object($v) && !$v instanceof \UnitEnum) {
                    $m = 'o' . spl_object_id($v);
                    $children = get_object_vars($v);
                } else {
                    $out[$t][$k] = $v;
                    continue;
                }
                if ($m !== null && isset($onPath[$m])) {
                    $out[$t][$k] = null;
                    continue;
                }
                if ($m !== null) {
                    $onPath[$m] = true;
                }
                $t++;
                $src[$t] = $children;
                $keys[$t] = array_keys($children);
                $pos[$t] = 0;
                $out[$t] = [];
                $pk[$t] = $k;
                $mark[$t] = $m;
                $down = true;
                break;
            }
            if ($down) {
                continue;
            }
            $done = $out[$t];
            if ($t === 0) {
                return $done;
            }
            $key = $pk[$t];
            if ($mark[$t] !== null) {
                unset($onPath[$mark[$t]]);
            }
            unset($src[$t], $keys[$t], $pos[$t], $out[$t], $pk[$t], $mark[$t]);
            $t--;
            $out[$t][$key] = $done;
        }
    }
}
