<?php
/*
contract: kit-api — what the kit promises every PHP project that vendors
it (core 13, PHP instance), and this project's own fixtures runner. Port
of lua/code/contracts/kit_api.lua. Only a contracts phase changes this
file.

Between: kit -> fixtures (and consumers outside this project).

MODULES (files under php/code/; consumers vendor php/code/core/,
php/code/kit/ and the shared harness php/code/harness.php)

  kit/data.php   plain data and its canonical JSON (decision 5)
    enum Siso\Kit\Json { case EmptyArray; }
                           the empty JSON array, a value (an enum case:
                           immutable, holds nothing, kept by identity in
                           event data, core-api IMMUTABLE SENTINELS)
    final class Siso\Kit\Data (static methods only, no state):
    Data::array(array $t) → $t for a non-empty list, Json::EmptyArray
                           for [], and throws \InvalidArgumentException for
                           an array that is not a list
    Data::isArray($v)      true for a non-empty list or Json::EmptyArray
    Data::encode(mixed $v, ?string &$why = null): ?string
                           canonical JSON, or null with $why; $why is
                           null on success. Errors are returned, never
                           thrown.
    Data::decode(string $s, ?string &$why = null): mixed
                           the value, or null with $why ($why null on
                           success: decode('null') is null with $why null)
    JSON null is PHP null (no sentinel: PHP has a value for it). A
    non-empty list is a JSON array; Json::EmptyArray is []; an empty
    unmarked PHP array is the empty object {} (Lua's rule: an empty
    unmarked table is an object); any other array is an object, its keys
    rendered as strings (PHP turns decimal-integer string keys into ints:
    {"1": x} decodes to [1 => x], a substrate note).
    Canonical form, as Lua's (lua/code/kit/data.lua header):
      - object keys sorted byte-wise (strcmp on the string form); no
        whitespace anywhere;
      - ints as digits; floats finite, as the shortest round-trip digits
        (not depending on serialize_precision), laid out as ECMAScript's
        Number::toString, always keeping "." or an exponent so the type
        survives: 1.0 is "1.0", 1e21 is "1e+21", -0.0 is "-0.0";
      - NaN and ±INF are rejected;
      - strings valid UTF-8 (preg_match('//u')), escaped as Lua's: `"`,
        `\` and bytes below 0x20 (\b \f \n \r \t, else \u00XX); every
        other byte as is;
      - an Event, an enum case other than Json::EmptyArray, any other
        object and a resource are rejected; nesting limit 512.
    Event data (core-api: arrays, values, readonly) is encoded as the
    plain data it is, so encode($event->data) === encode($data) for the
    data an event was built from. Residue, samples and observer records
    can be serialised as they are handed out.
    Decoding is strict RFC 8259 (no trailing commas, comments, NaN,
    leading zeros or duplicate keys, compared as strings before PHP's int
    coercion; one value): objects become arrays ({} becomes []),
    non-empty arrays become lists, [] becomes Json::EmptyArray, integers
    must fit PHP_INT, other numbers are floats, \u escapes are decoded
    with surrogate pairs joined, a lone surrogate is an error.
    Nothing kit/data.php hands out gives a gate something it could write
    and later read back: arrays are values and the one sentinel is an
    enum case.

  Command line (run from the project root):
    php php/code/kit/run.php [tests-dir]
                           every *.test.php under the tests directory (default
                           php/sisoTests) at any depth, except under a
                           directory named fixtures or ending in -fixtures or
                           _fixtures, each in its own process, byte-wise
                           path order; a suite passes when it exits 0 and its
                           last "<suite>: P/T passed" line has P = T and no
                           FAILED. Final line "run: S suites, P/T passed"
                           (or ", F FAILED (files…)"); exit 0 only when all
                           pass.

SUBSTRATE (PHP)
  - Not ported (tooling for Lua gate authors): kit.sha256, kit.helper (the
    per-gate test API), the census, the lint, the digest and kit.canon.
    Their exports and the sha256 probe have no counterpart here.
  - Lua's array mark (getmetatable answers "kit.data.array") becomes
    list-ness itself plus one enum case for the empty array: a PHP array
    carries no mark, and a non-empty list is unambiguous. Lua's
    data.null (a "readonly:" sentinel) becomes PHP null.
  - Lua's "marks hand out strings, never a table" becomes: Data::array([])
    is an enum case (immutable by the language), never an object that
    could hold state.
  - Lua's `freeze` option (encode must read frozen data) becomes the
    optional 'Event' class: encode must read an event's data as the
    plain data it was built from, the event must keep Json::EmptyArray as
    itself, and encode must refuse an Event.

KitApi::check($kit) runs the module half of this contract against
`$kit = ['Data' => class-string, 'Json' => enum-class-string, 'Event' =>
class-string (optional)]` and returns a list of problems.
*/
declare(strict_types=1);

namespace Siso\Contracts;

require_once __DIR__ . '/core_api.php';

final class KitApi
{
    public const EXPORTS = [
        'Data' => ['array' => 'static method', 'isArray' => 'static method', 'encode' => 'static method', 'decode' => 'static method'],
        'Json' => ['EmptyArray' => 'enum case'],
    ];

    public const TOOLS = ['php/code/kit/run.php'];

    public const CANONICAL = '{"a":[1.0,"x",true,null],"b":1,"e":[]}';

    private function __construct()
    {
    }

    public static function check(array $kit): array
    {
        $p = [];
        $fail = static function (string $s) use (&$p): void {
            $p[] = $s;
        };
        $Data = $kit['Data'] ?? null;
        $Json = $kit['Json'] ?? null;
        if (!is_string($Data) || !class_exists($Data)) {
            $fail('kit Data is missing');
        } else {
            foreach (self::EXPORTS['Data'] as $m => $_) {
                if (!method_exists($Data, $m)) {
                    $fail('kit Data::' . $m . ' is missing');
                } else {
                    $rm = new \ReflectionMethod($Data, $m);
                    if (!$rm->isStatic() || !$rm->isPublic()) {
                        $fail('kit Data::' . $m . ' is not a public static method');
                    }
                }
            }
        }
        if (!is_string($Json) || !enum_exists($Json)) {
            $fail('kit Json is missing');
        } elseif (!defined($Json . '::EmptyArray') || !(constant($Json . '::EmptyArray') instanceof \UnitEnum)) {
            $fail('kit Json::EmptyArray is missing');
        }
        sort($p, SORT_STRING);
        if ($p !== []) {
            return $p;
        }

        try {
            $E = constant($Json . '::EmptyArray');
            foreach (CoreApi::staticStateOf($Data) as $what) {
                $fail('kit Data holds static state: ' . $what);
            }
            $v = ['b' => 1, 'a' => $Data::array([1.0, 'x', true, null]), 'e' => $Data::array([])];
            $why = 'not set';
            $s = $Data::encode($v, $why);
            if ($s !== self::CANONICAL) {
                $fail('Data::encode is not canonical: ' . CoreApi::show($s ?? $why));
            } elseif ($why !== null) {
                $fail('Data::encode left $why set on success');
            }
            $why2 = 'not set';
            $back = $Data::decode(self::CANONICAL, $why2);
            if (!is_array($back) || !$Data::isArray($back['a'] ?? null) || !$Data::isArray($back['e'] ?? null)) {
                $fail('Data::decode loses arrays');
            }
            if (is_array($back) && ($back['e'] ?? null) !== $E) {
                $fail('Data::decode does not give Json::EmptyArray for []');
            }
            if (is_array($back) && (!is_float($back['a'][0] ?? null) || !is_array($back['a'] ?? null)
                || !array_key_exists(3, $back['a']) || $back['a'][3] !== null)) {
                $fail('Data::decode loses a float or null');
            }
            if (is_array($back) && !is_int($back['b'] ?? null)) {
                $fail('Data::decode turns an integer into a float');
            }
            if ($why2 !== null) {
                $fail('Data::decode left $why set on success');
            }
            $whyN = 'not set';
            $null = $Data::decode('null', $whyN);
            if ($null !== null || $whyN !== null) {
                $fail("Data::decode('null') is " . CoreApi::show($null) . ' (why ' . CoreApi::show($whyN) . '), not PHP null');
            }
            if ($Data::isArray([])) {
                $fail('an unmarked empty array is an array');
            }
            if ($Data::encode([]) !== '{}') {
                $fail('Data::encode([]) is not the empty object {}');
            }
            $marked = $Data::array([]);
            if ($marked !== $E) {
                $fail('Data::array([]) is ' . CoreApi::show($marked) . ', not Json::EmptyArray');
            }
            $list = [1, 2];
            if ($Data::array($list) !== $list) {
                $fail('Data::array on a list is not that list');
            }
            try {
                $Data::array(['k' => 1]);
                $fail('Data::array accepted an array that is not a list');
            } catch (\InvalidArgumentException) {
            }
            $whyB = null;
            if ($Data::encode(['f' => static fn () => 1], $whyB) !== null || !is_string($whyB)) {
                $fail('Data::encode accepted a Closure');
            }
            if ($Data::encode(['o' => new \stdClass()]) !== null) {
                $fail('Data::encode accepted an object');
            }
            $whyD = null;
            if ($Data::decode('{', $whyD) !== null || !is_string($whyD)) {
                $fail('Data::decode does not return why a text is not JSON');
            }
            $Event = $kit['Event'] ?? null;
            if (is_string($Event) && class_exists($Event)) {
                $sample = static fn (): array => [
                    'b' => 1, 'a' => $Data::array([1.0, 'x', true, null]), 'e' => $Data::array([]),
                    'o' => ['k' => 'v', 'n' => [2]], 'l' => [1, 2],
                ];
                $plain = $Data::encode($sample());
                $ev = new $Event('t', $sample());
                $whyF = null;
                $fromEvent = $Data::encode($ev->data, $whyF);
                if ($fromEvent === null || $fromEvent !== $plain) {
                    $fail('Data::encode does not read event data as the plain data it is: ' . CoreApi::show($fromEvent ?? $whyF));
                }
                if (($ev->data['e'] ?? null) !== $E) {
                    $fail('an event did not keep Json::EmptyArray as itself');
                }
                if (!$Data::isArray($ev->data['a'] ?? null)) {
                    $fail('a list in event data is not an array');
                }
                if ($Data::encode(['e' => $ev]) !== null) {
                    $fail('Data::encode accepted an Event');
                }
            }
        } catch (\Throwable $err) {
            $fail('raised ' . get_class($err) . ': ' . $err->getMessage());
        }
        return $p;
    }
}
