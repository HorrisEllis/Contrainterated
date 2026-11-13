<?php
/*
kit/data.php — plain data and its canonical JSON (kit-api, decision 5).
Port of lua/code/kit/data.lua (itself from jää 2.14.4 lib.json). Requires
nothing.

  enum Siso\Kit\Json { case EmptyArray; }
                          the empty JSON array, a value: an enum case is
                          immutable and holds nothing (core-api IMMUTABLE
                          SENTINELS), so event data keeps it by identity.
  Data::array(array $t)   $t for a non-empty list, Json::EmptyArray for [],
                          \InvalidArgumentException for any other array
  Data::isArray($v)       true for a non-empty list or Json::EmptyArray
  Data::encode($v, &$why) canonical text, or null with $why (null on success)
  Data::decode($s, &$why) the value, or null with $why (null on success:
                          decode('null') is null with $why null)

Errors are returned, never thrown (Data::array throws on an array that is
not a list: a caller's bug, not data; a non-string given to decode is
refused by its type declaration).

Values (kit-api): JSON null is PHP null. A non-empty list is a JSON array,
Json::EmptyArray is [], an empty PHP array is the empty object {} (Lua's
rule: an empty unmarked table is an object), and any other array is an
object whose keys are rendered as strings. PHP turns a decimal-integer
string key into an int, so {"1": x} decodes to [1 => x], and {"0": x}
decodes to [0 => x], a list, which encodes back as [x] (substrate note).

Canonical form, as Lua's:
  - object keys sorted byte-wise (strcmp on the string form); no
    whitespace anywhere;
  - ints as digits; floats finite, as the shortest round-trip digits (the
    closest of them to the value when several have the same length),
    found with sprintf('%.Ne') and checked by parsing back, so the text
    never depends on serialize_precision or precision; laid out as
    ECMAScript's Number::toString (decimal for 1e-7 <= |x| < 1e21, else
    d.ddde±n), always keeping "." or an exponent so the type survives:
    1.0 is "1.0", 1e21 is "1e+21", -0.0 is "-0.0";
  - NaN and ±INF are rejected;
  - strings valid UTF-8 (preg_match('//u'): no overlongs, no encoded
    surrogates, nothing above U+10FFFF); `"`, `\` and bytes below 0x20
    escaped (\b \f \n \r \t, else \u00xx); every other byte as is;
  - an Event, an enum case other than Json::EmptyArray, any other object
    and a resource are rejected.

Nesting is limited to 512 arrays/objects (Json::EmptyArray and the empty
object count), in both directions. Encoding and decoding are iterative
(an explicit stack, never PHP recursion), so input of any depth fails at
level 513 at a cost proportional to 512 levels. A recursive array (only
possible through a PHP reference, `$a['self'] = &$a`) is infinitely deep
and so fails as "nesting too deep": Lua's "cycle" counterpart. Arrays are
values in PHP, so the same array in two places is no cycle.

Error paths name PHP's own keys from the root: $.a.b[1].c ([i] for a list
index, 0-based as PHP's lists are; .k for an object key; .<key> when the
key itself is not valid UTF-8). Paths are built only on error. Decode
errors name a byte position counted from 1, as Lua's do, so the same text
gets the same message in both instances.

Decoding is strict RFC 8259 (no trailing commas, comments, NaN, leading
zeros or duplicate keys; one value; whitespace is space, tab, LF, CR).
Objects become arrays ({} becomes []), non-empty arrays become lists, []
becomes Json::EmptyArray. Integers (no "." or exponent) must fit PHP_INT
(64 bits); other numbers are floats, and a float that overflows is an
error. \u escapes are decoded to UTF-8 with surrogate pairs joined; a
lone surrogate is an error. Duplicate keys are found among the decoded
string keys (PHP's key coercion is one-to-one on strings, so two keys
collide only when their strings are equal).

Pure: Data and Json hold no static state (no static property, no static
variable), no I/O, nothing written after load. Output never depends on
PHP's array order for objects: keys are collected, then sorted. Nothing
kit/data.php hands out gives a gate something it could write and later
read back: arrays are values and the one sentinel is an enum case.
*/
declare(strict_types=1);

namespace Siso\Kit;

enum Json
{
    case EmptyArray;
}

final class Data
{
    /** nested arrays/objects; encode and decode alike */
    private const MAX_DEPTH = 512;

    /** what a JSON string escapes: `"`, `\` and bytes below 0x20 */
    private const ESC = [
        '"' => '\\"', '\\' => '\\\\',
        "\x00" => '\\u0000', "\x01" => '\\u0001', "\x02" => '\\u0002', "\x03" => '\\u0003',
        "\x04" => '\\u0004', "\x05" => '\\u0005', "\x06" => '\\u0006', "\x07" => '\\u0007',
        "\x08" => '\\b', "\x09" => '\\t', "\x0a" => '\\n', "\x0b" => '\\u000b',
        "\x0c" => '\\f', "\x0d" => '\\r', "\x0e" => '\\u000e', "\x0f" => '\\u000f',
        "\x10" => '\\u0010', "\x11" => '\\u0011', "\x12" => '\\u0012', "\x13" => '\\u0013',
        "\x14" => '\\u0014', "\x15" => '\\u0015', "\x16" => '\\u0016', "\x17" => '\\u0017',
        "\x18" => '\\u0018', "\x19" => '\\u0019', "\x1a" => '\\u001a', "\x1b" => '\\u001b',
        "\x1c" => '\\u001c', "\x1d" => '\\u001d', "\x1e" => '\\u001e', "\x1f" => '\\u001f',
    ];

    /** the bytes that end a run of plain string bytes while decoding */
    private const STOP = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";

    private const UNESC = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t"];

    private const WS = " \t\n\r";

    private function __construct()
    {
    }

    // ── arrays ────────────────────────────────────

    public static function array(array $t): array|Json
    {
        if ($t === []) {
            return Json::EmptyArray;
        }
        if (!array_is_list($t)) {
            throw new \InvalidArgumentException('Data::array: the array is not a list (its keys are not exactly 0..n-1)');
        }
        return $t;
    }

    public static function isArray(mixed $v): bool
    {
        return $v === Json::EmptyArray || (is_array($v) && $v !== [] && array_is_list($v));
    }

    // ── floats ────────────────────────────────────

    /**
     * Shortest digits d1..dk and exponent n with x = 0.d1..dk × 10^n
     * (ECMAScript's k and n), for finite x > 0. For each length p the
     * correctly rounded p-digit decimal M is tried, then its neighbours
     * M-1 and M+1: at an exact power of two the rounding interval of x is
     * lopsided, so a neighbour can round-trip when M does not. Among the
     * p-digit decimals that round-trip, the one closest to x is taken.
     *
     * @return array{0: string, 1: int}
     */
    private static function shortest(float $x): array
    {
        for ($p = 1; $p <= 17; $p++) {
            // "." forced as the decimal point, whatever the locale says
            $s = str_replace(',', '.', sprintf('%.' . ($p - 1) . 'e', $x));
            if (preg_match('/^(\d[.\d]*)e([-+]\d+)$/', $s, $m) !== 1) {
                break;
            }
            $M = (int)str_replace('.', '', $m[1]);
            $scale = (int)$m[2] - ($p - 1);          // x ≈ M × 10^scale
            $best = null;
            if ((float)($M . 'e' . $scale) === $x) {
                $best = $M;
            } else {
                $lo = $M - 1;
                $hi = $M + 1;
                $vLo = (float)($lo . 'e' . $scale);
                $vHi = (float)($hi . 'e' . $scale);
                $okLo = $lo > 0 && $vLo === $x;
                $okHi = $vHi === $x;
                if ($okLo && $okHi) {
                    $best = abs($x - $vLo) <= abs($vHi - $x) ? $lo : $hi;
                } elseif ($okLo) {
                    $best = $lo;
                } elseif ($okHi) {
                    $best = $hi;
                }
            }
            if ($best !== null) {
                $digits = (string)$best;
                $n = strlen($digits) + $scale;
                $digits = rtrim($digits, '0');
                return [$digits === '' ? '0' : $digits, $n];
            }
        }
        // not reached for a finite double: 17 digits always round-trip
        $s = sprintf('%.16e', $x);
        preg_match('/^(\d)\.(\d+)e([-+]\d+)$/', str_replace(',', '.', $s), $m);
        $digits = rtrim($m[1] . $m[2], '0');
        return [$digits === '' ? '0' : $digits, (int)$m[3] + 1];
    }

    /** the canonical text of a float, or null with $why */
    private static function formatFloat(float $x, ?string &$why): ?string
    {
        if (is_nan($x)) {
            $why = 'NaN is not allowed';
            return null;
        }
        if (is_infinite($x)) {
            $why = 'Infinity is not allowed';
            return null;
        }
        if ($x === 0.0) {
            return fdiv(1.0, $x) < 0 ? '-0.0' : '0.0';
        }
        $sign = '';
        if ($x < 0) {
            $sign = '-';
            $x = -$x;
        }
        [$d, $n] = self::shortest($x);
        $k = strlen($d);
        if ($k <= $n && $n <= 21) {
            $out = $d . str_repeat('0', $n - $k) . '.0';
        } elseif (0 < $n && $n <= 21) {
            $out = substr($d, 0, $n) . '.' . substr($d, $n);
        } elseif (-6 < $n && $n <= 0) {
            $out = '0.' . str_repeat('0', -$n) . $d;
        } else {
            $e = $n - 1;
            $es = $e < 0 ? '-' . (string)(-$e) : '+' . (string)$e;
            $out = $k === 1 ? $d . 'e' . $es : $d[0] . '.' . substr($d, 1) . 'e' . $es;
        }
        return $sign . $out;
    }

    // ── encoding ──────────────────────────────────

    /** a JSON string, or null when $s is not valid UTF-8 */
    private static function quote(string $s): ?string
    {
        if (preg_match('//u', $s) !== 1) {
            return null;
        }
        return '"' . strtr($s, self::ESC) . '"';
    }

    /**
     * The path of the value being encoded: each open container's step to
     * the child it is on. Built only on error.
     */
    private static function pathOf(array $stack, string $extra = ''): string
    {
        $out = '$';
        foreach ($stack as $f) {
            $i = $f['i'] - 1;
            $out .= $f['list'] ? '[' . $i . ']' : '.' . $f['keys'][$i];
        }
        return $out . $extra;
    }

    /**
     * Writes a scalar (or an empty container) to $out, or opens a
     * container on $stack. → null, or why $v is not data.
     */
    private static function put(mixed $v, array &$out, array &$stack): ?string
    {
        if ($v === null) {
            $out[] = 'null';
            return null;
        }
        if (is_bool($v)) {
            $out[] = $v ? 'true' : 'false';
            return null;
        }
        if (is_int($v)) {
            $out[] = (string)$v;
            return null;
        }
        if (is_float($v)) {
            $why = null;
            $s = self::formatFloat($v, $why);
            if ($s === null) {
                return $why;
            }
            $out[] = $s;
            return null;
        }
        if (is_string($v)) {
            $q = self::quote($v);
            if ($q === null) {
                return 'string is not valid UTF-8';
            }
            $out[] = $q;
            return null;
        }
        if ($v === Json::EmptyArray || is_array($v)) {
            if (count($stack) >= self::MAX_DEPTH) {
                return 'nesting too deep';
            }
            if ($v === Json::EmptyArray) {
                $out[] = '[]';
                return null;
            }
            if ($v === []) {
                $out[] = '{}';
                return null;
            }
            if (array_is_list($v)) {
                $out[] = '[';
                $stack[] = ['list' => true, 'v' => $v, 'keys' => null, 'n' => count($v), 'i' => 0];
                return null;
            }
            $keys = [];
            foreach ($v as $k => $_) {
                $keys[] = (string)$k;
            }
            usort($keys, 'strcmp');
            $out[] = '{';
            $stack[] = ['list' => false, 'v' => $v, 'keys' => $keys, 'n' => count($keys), 'i' => 0];
            return null;
        }
        if ($v instanceof \UnitEnum) {
            return 'the enum case ' . get_class($v) . '::' . $v->name . ' is not data (Json::EmptyArray is the one that is)';
        }
        if ($v instanceof \Siso\Core\Event) {
            return 'an Event is not data (encode its type and data)';
        }
        return 'a ' . get_debug_type($v) . ' is not data';
    }

    public static function encode(mixed $v, ?string &$why = null): ?string
    {
        $why = null;
        $out = [];
        $stack = [];
        $err = self::put($v, $out, $stack);
        while ($err === null && $stack !== []) {
            $t = count($stack) - 1;
            $i = $stack[$t]['i'];
            if ($i >= $stack[$t]['n']) {
                $out[] = $stack[$t]['list'] ? ']' : '}';
                array_pop($stack);
                continue;
            }
            $stack[$t]['i'] = $i + 1;
            if ($i > 0) {
                $out[] = ',';
            }
            if ($stack[$t]['list']) {
                $child = $stack[$t]['v'][$i];
            } else {
                $k = $stack[$t]['keys'][$i];
                $q = self::quote($k);
                if ($q === null) {
                    $why = 'Data::encode: string is not valid UTF-8 at ' . self::pathOf(array_slice($stack, 0, $t)) . '.<key>';
                    return null;
                }
                $out[] = $q . ':';
                $child = $stack[$t]['v'][$k];
            }
            $err = self::put($child, $out, $stack);
        }
        if ($err !== null) {
            $why = 'Data::encode: ' . $err . ' at ' . self::pathOf($stack);
            return null;
        }
        return implode('', $out);
    }

    // ── decoding ──────────────────────────────────

    /** a decoding failure: the message and the byte (counted from 1) */
    private static function at(string $msg, int $i): string
    {
        return 'Data::decode: ' . $msg . ' at byte ' . ($i + 1);
    }

    private static function skipWs(string $s, int $i): int
    {
        return $i + strspn($s, self::WS, $i);
    }

    /** UTF-8 for a code point (U+0000..U+10FFFF, no surrogates) */
    private static function utf8(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    /** four hex digits at $j, or null */
    private static function hex4(string $s, int $j): ?int
    {
        $h = substr($s, $j, 4);
        if (strlen($h) !== 4 || strspn($h, '0123456789abcdefABCDEF') !== 4) {
            return null;
        }
        return (int)hexdec($h);
    }

    /**
     * The string starting at $i (where $s[$i] is '"').
     * @return array{0: ?string, 1: int, 2: ?string} value, next position, why
     */
    private static function decodeString(string $s, int $i): array
    {
        $len = strlen($s);
        $parts = [];
        $j = $i + 1;
        while (true) {
            $run = strcspn($s, self::STOP, $j);
            if ($run > 0) {
                $parts[] = substr($s, $j, $run);
                $j += $run;
            }
            if ($j >= $len) {
                return [null, $j, self::at('unterminated string', $i)];
            }
            $c = $s[$j];
            if ($c === '"') {
                $str = implode('', $parts);
                if (preg_match('//u', $str) !== 1) {
                    return [null, $j, self::at('string is not valid UTF-8', $i)];
                }
                return [$str, $j + 1, null];
            }
            if ($c !== '\\') {
                return [null, $j, self::at('control character in string', $j)];
            }
            $e = $j + 1 < $len ? $s[$j + 1] : '';
            if (isset(self::UNESC[$e])) {
                $parts[] = self::UNESC[$e];
                $j += 2;
                continue;
            }
            if ($e !== 'u') {
                return [null, $j, self::at('bad escape', $j)];
            }
            $cp = self::hex4($s, $j + 2);
            if ($cp === null) {
                return [null, $j, self::at('bad \\u escape', $j)];
            }
            $j += 6;
            if ($cp >= 0xD800 && $cp <= 0xDBFF) {
                $lo = substr($s, $j, 2) === '\\u' ? self::hex4($s, $j + 2) : null;
                if ($lo === null || $lo < 0xDC00 || $lo > 0xDFFF) {
                    return [null, $j, self::at('lone high surrogate', $j)];
                }
                $cp = 0x10000 + (($cp - 0xD800) << 10) + ($lo - 0xDC00);
                $j += 6;
            } elseif ($cp >= 0xDC00 && $cp <= 0xDFFF) {
                return [null, $j, self::at('lone low surrogate', $j)];
            }
            $parts[] = self::utf8($cp);
        }
    }

    /**
     * The number starting at $i, validated against the RFC 8259 grammar.
     * @return array{0: int|float|null, 1: int, 2: ?string} value, next position, why
     */
    private static function decodeNumber(string $s, int $i): array
    {
        if (preg_match('/\G(-?\d+)(\.?\d*)([eE]?[-+]?\d*)/', $s, $m, 0, $i) !== 1) {
            return [null, $i, self::at('bad number', $i)];
        }
        [, $int, $frac, $exp] = $m;
        if (preg_match('/^-?0\d/', $int) === 1) {
            return [null, $i, self::at('leading zero', $i)];
        }
        if ($frac === '.') {
            return [null, $i, self::at('bad fraction', $i)];
        }
        if ($exp !== '' && preg_match('/^[eE][-+]?\d+$/', $exp) !== 1) {
            return [null, $i, self::at('bad exponent', $i)];
        }
        $text = $int . $frac . $exp;
        $next = $i + strlen($text);
        if ($frac === '' && $exp === '') {
            $v = (int)$text;
            // (int) saturates when the digits overflow 64 bits
            if ((string)$v !== ($text === '-0' ? '0' : $text)) {
                return [null, $i, self::at('integer out of 64-bit range', $i)];
            }
            return [$v, $next, null];
        }
        $v = (float)$text;
        if (is_infinite($v)) {
            return [null, $i, self::at('number out of range', $i)];
        }
        return [$v, $next, null];
    }

    /**
     * An object key and its ':' at $i (after whitespace).
     * @return array{0: ?string, 1: int, 2: ?string} key, position after ':', why
     */
    private static function decodeKey(string $s, int $i, array $obj): array
    {
        if (($s[$i] ?? '') !== '"') {
            return [null, $i, self::at('expected a string key', $i)];
        }
        $at = $i;
        [$k, $i, $why] = self::decodeString($s, $i);
        if ($why !== null) {
            return [null, $i, $why];
        }
        if (array_key_exists($k, $obj)) {
            return [null, $i, self::at('duplicate key ' . self::quote($k), $at)];
        }
        $i = self::skipWs($s, $i);
        if (($s[$i] ?? '') !== ':') {
            return [null, $i, self::at("expected ':'", $i)];
        }
        return [$k, $i + 1, null];
    }

    public static function decode(string $s, ?string &$why = null): mixed
    {
        $why = null;
        $len = strlen($s);
        $i = 0;
        // open containers: ['list' => bool, 'v' => array, 'key' => ?string]
        $stack = [];
        while (true) {
            // ── a value is expected at $i
            $i = self::skipWs($s, $i);
            $c = $s[$i] ?? '';
            $value = null;
            $err = null;
            if ($c === '{' || $c === '[') {
                if (count($stack) >= self::MAX_DEPTH) {
                    $why = self::at('nesting too deep', $i);
                    return null;
                }
                $i = self::skipWs($s, $i + 1);
                if ($c === '{') {
                    if (($s[$i] ?? '') === '}') {
                        $value = [];
                        $i++;
                    } else {
                        [$k, $i, $err] = self::decodeKey($s, $i, []);
                        if ($err !== null) {
                            $why = $err;
                            return null;
                        }
                        $stack[] = ['list' => false, 'v' => [], 'key' => $k];
                        continue;
                    }
                } else {
                    if (($s[$i] ?? '') === ']') {
                        $value = Json::EmptyArray;
                        $i++;
                    } else {
                        $stack[] = ['list' => true, 'v' => [], 'key' => null];
                        continue;
                    }
                }
            } elseif ($c === '"') {
                [$value, $i, $err] = self::decodeString($s, $i);
            } elseif ($c === '-' || ($c !== '' && strspn($c, '0123456789') === 1)) {
                [$value, $i, $err] = self::decodeNumber($s, $i);
            } elseif ($i >= $len) {
                $err = self::at('unexpected end of input', $i);
            } elseif (substr_compare($s, 'true', $i, 4) === 0) {
                $value = true;
                $i += 4;
            } elseif (substr_compare($s, 'false', $i, 5) === 0) {
                $value = false;
                $i += 5;
            } elseif (substr_compare($s, 'null', $i, 4) === 0) {
                $value = null;
                $i += 4;
            } else {
                $err = self::at('unexpected character', $i);
            }
            if ($err !== null) {
                $why = $err;
                return null;
            }

            // ── $value is complete: place it, closing containers as they end
            while (true) {
                if ($stack === []) {
                    $i = self::skipWs($s, $i);
                    if ($i < $len) {
                        $why = 'Data::decode: trailing characters at byte ' . ($i + 1);
                        return null;
                    }
                    return $value;
                }
                $t = count($stack) - 1;
                $i = self::skipWs($s, $i);
                $d = $s[$i] ?? '';
                if ($stack[$t]['list']) {
                    $stack[$t]['v'][] = $value;
                    if ($d === ',') {
                        $i++;
                        continue 2;
                    }
                    if ($d !== ']') {
                        $why = self::at("expected ',' or ']'", $i);
                        return null;
                    }
                } else {
                    $stack[$t]['v'][$stack[$t]['key']] = $value;
                    if ($d === ',') {
                        $i = self::skipWs($s, $i + 1);
                        [$k, $i, $err] = self::decodeKey($s, $i, $stack[$t]['v']);
                        if ($err !== null) {
                            $why = $err;
                            return null;
                        }
                        $stack[$t]['key'] = $k;
                        continue 2;
                    }
                    if ($d !== '}') {
                        $why = self::at("expected ',' or '}'", $i);
                        return null;
                    }
                }
                $i++;
                $value = array_pop($stack)['v'];
            }
        }
    }
}
