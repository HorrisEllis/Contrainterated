<?php
// contract test: kit-api (php/code/contracts/kit_api.php).
// The module check names what is missing and what misbehaves, and passes
// on a data class that answers as the contract says. (Small stand-ins
// defined here take the kit's place: php/code/kit/data.php is built by a
// kit phase; the stand-ins answer only what the check asks.)
declare(strict_types=1);

namespace Siso\Tests\ContractKitApi;

require_once __DIR__ . '/../code/harness.php';
require_once __DIR__ . '/../code/contracts/kit_api.php';
require_once __DIR__ . '/contract-fake-core.php';

use Siso\Contracts\KitApi as K;
use Siso\Harness\H;
use Siso\Tests\Fake\Event;

function joined(array $list): string
{
    return implode('; ', $list);
}

function has(array $list, string $needle): bool
{
    foreach ($list as $s) {
        if (str_contains($s, $needle)) {
            return true;
        }
    }
    return false;
}

enum Json
{
    case EmptyArray;
}

/** a stand-in that answers as the contract says (no strict-RFC decoding: the kit's own tests pin that) */
class Data
{
    public static function array(array $t): mixed
    {
        if ($t === []) {
            return Json::EmptyArray;
        }
        if (!array_is_list($t)) {
            throw new \InvalidArgumentException('Data::array: not a list');
        }
        return $t;
    }

    public static function isArray(mixed $v): bool
    {
        return $v === Json::EmptyArray || (is_array($v) && $v !== [] && array_is_list($v));
    }

    public static function encode(mixed $v, ?string &$why = null): ?string
    {
        $why = null;
        $s = static::enc($v, $why);
        return $why === null ? $s : null;
    }

    protected static function enc(mixed $v, ?string &$why): string
    {
        if ($v === null || is_bool($v) || is_int($v)) {
            return json_encode($v);
        }
        if (is_float($v)) {
            $s = json_encode($v);
            return preg_match('/[.eE]/', (string)$s) ? (string)$s : $s . '.0';
        }
        if (is_string($v)) {
            return (string)json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($v === Json::EmptyArray) {
            return '[]';
        }
        if (is_array($v) && $v !== [] && array_is_list($v)) {
            $parts = [];
            foreach ($v as $x) {
                $parts[] = static::enc($x, $why);
            }
            return '[' . implode(',', $parts) . ']';
        }
        if (is_array($v)) {
            $ks = array_map('strval', array_keys($v));
            sort($ks, SORT_STRING);
            $parts = [];
            foreach ($ks as $k) {
                $parts[] = json_encode($k) . ':' . static::enc($v[$k], $why);
            }
            return '{' . implode(',', $parts) . '}';
        }
        $why = 'not data: ' . get_debug_type($v);
        return '';
    }

    public static function decode(string $s, ?string &$why = null): mixed
    {
        $v = json_decode($s, false);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $why = json_last_error_msg();
            return null;
        }
        $why = null;
        return static::shape($v);
    }

    protected static function shape(mixed $v): mixed
    {
        if ($v instanceof \stdClass) {
            return array_map(static fn ($x) => static::shape($x), get_object_vars($v));
        }
        if (is_array($v)) {
            return $v === [] ? Json::EmptyArray : array_map(static fn ($x) => static::shape($x), $v);
        }
        return $v;
    }
}

final class GoodData extends Data
{
}

/** lacks decode */
final class MissingDecode
{
    public static function array(array $t): mixed
    {
        return $t;
    }

    public static function isArray(mixed $v): bool
    {
        return false;
    }

    public static function encode(mixed $v, ?string &$why = null): ?string
    {
        return null;
    }
}

final class NonCanonical extends Data
{
    public static function encode(mixed $v, ?string &$why = null): ?string
    {
        $why = null;
        return '{"b":1,"a":[1,"x",true,null],"e":[]}';
    }
}

/** JSON null decoded as a sentinel object instead of PHP null */
final class NullSentinel extends Data
{
    protected static function shape(mixed $v): mixed
    {
        if ($v === null) {
            return new \stdClass();
        }
        return parent::shape($v);
    }
}

/** the empty-array mark as a writable object, which state could ride on */
final class ObjectMark extends Data
{
    public static function array(array $t): mixed
    {
        return $t === [] ? new \ArrayObject() : parent::array($t);
    }

    public static function isArray(mixed $v): bool
    {
        return $v instanceof \ArrayObject || parent::isArray($v);
    }
}

/** an encoder that serialises an Event as if it were data */
final class EventEncoder extends Data
{
    protected static function enc(mixed $v, ?string &$why): string
    {
        if ($v instanceof Event) {
            return parent::enc(['data' => $v->data, 'type' => $v->type], $why);
        }
        return parent::enc($v, $why);
    }
}

/** a data class with a static cache */
final class CachingData extends Data
{
    public static array $cache = [];
}

H::test('a data class that answers as the contract says passes', function (): void {
    $p = K::check(['Data' => GoodData::class, 'Json' => Json::class]);
    H::assert($p === [], joined($p));
});

H::test('with an Event class given, the stand-in still passes', function (): void {
    $p = K::check(['Data' => GoodData::class, 'Json' => Json::class, 'Event' => Event::class]);
    H::assert($p === [], joined($p));
});

H::test('missing classes and methods are named', function (): void {
    $p = K::check(['Data' => MissingDecode::class, 'Json' => __NAMESPACE__ . '\NoSuchEnum']);
    H::assert(has($p, 'kit Data::decode is missing'), joined($p));
    H::assert(has($p, 'kit Json is missing'), joined($p));
    $p = K::check([]);
    H::assert(has($p, 'kit Data is missing'), joined($p));
});

H::test('a non-canonical encoder is named', function (): void {
    H::assert(has(K::check(['Data' => NonCanonical::class, 'Json' => Json::class]), 'not canonical'), 'encoder');
});

H::test('a JSON null that is not PHP null is named', function (): void {
    $p = K::check(['Data' => NullSentinel::class, 'Json' => Json::class]);
    H::assert(has($p, "Data::decode('null')"), joined($p));
    H::assert(has($p, 'loses a float or null'), joined($p));
});

H::test('13.0.0: an empty-array mark that is a writable object is named', function (): void {
    $p = K::check(['Data' => ObjectMark::class, 'Json' => Json::class]);
    H::assert(has($p, 'Data::array([]) is ArrayObject'), joined($p));
});

H::test('with an Event class given, an encoder that takes an Event for data is named', function (): void {
    $p = K::check(['Data' => EventEncoder::class, 'Json' => Json::class, 'Event' => Event::class]);
    H::assert(has($p, 'Data::encode accepted an Event'), joined($p));
    H::assert(K::check(['Data' => EventEncoder::class, 'Json' => Json::class]) === [], 'without Event the probe does not run');
});

H::test('static state in the data class is named', function (): void {
    $p = K::check(['Data' => CachingData::class, 'Json' => Json::class]);
    H::assert(has($p, 'static state'), joined($p));
});

H::finish('contract kit-api');
