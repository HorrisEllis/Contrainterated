<?php
/*
core/rejection.php — Siso\Core\Rejection, what a rejected propagation
throws (K5).

Contract core-api REJECTION (PHP instance). Port of
lua/code/core/rejection.lua. A rejection carries the reason and the
identities of the delivery involved, and nothing else. Its public
properties are exactly:

  code       SISO_SELF_LOOP | SISO_CYCLE | SISO_GATE_THREW | SISO_NOT_EVENT
             (a string; getCode() answers it)
  message    a string; (string)$r and $r->getMessage() return it
  eventId    the delivery involved (null only for a refused entry)
  causeId    that delivery's cause (null for the entry delivery)
  eventType  that delivery's event type (null only for a refused entry)
  depth      SISO_CYCLE only
  cause      SISO_GATE_THREW only: exactly the \Throwable the gate threw

each null when its code does not carry it. File, line and trace are the
substrate's exception plumbing (protected, not fields).

  Rejection::selfLoop($delivery, $signature)
  Rejection::cycle($delivery, $gateCount)
  Rejection::gateThrew($delivery, $signature, $cause)
  Rejection::notEvent($delivery, $signature, $what)  $delivery null: a refused entry
  Rejection::is($v)

`$delivery` is the stream's view of one delivery: ['id' => int,
'causeId' => ?int, 'type' => string, 'depth' => int]. Each builder makes
a fresh object; nothing here is kept between calls (no static property,
no method with `static` variables). The constructor is private: only the
builders make rejections.

Lua X1 ("read a thrown table's message raw and under protection"): a
PHP \Throwable is an \Exception or an \Error, whose getMessage() is final
and reads the protected message field, so no code of the thrower's runs
when the message is read; it is still read under protection.

An observer is never shown this object: it receives Freeze::plain of it
(observer-seam THE REJECTION).
*/
declare(strict_types=1);

namespace Siso\Core;

final class Rejection extends \RuntimeException
{
    /** @var string */
    public $code;
    /** @var string */
    public $message;
    public ?int $eventId = null;
    public ?int $causeId = null;
    public ?string $eventType = null;
    public ?int $depth = null;
    public ?\Throwable $cause = null;

    private function __construct(string $code, string $message, ?array $delivery, ?\Throwable $cause = null)
    {
        parent::__construct($message, 0, $cause);
        $this->code = $code;
        $this->message = $message;
        if ($delivery !== null) {
            $this->eventId = $delivery['id'];
            $this->causeId = $delivery['causeId'];
            $this->eventType = $delivery['type'];
        }
    }

    public function __toString(): string
    {
        return (string)$this->message;
    }

    public static function is(mixed $v): bool
    {
        return $v instanceof self;
    }

    public static function selfLoop(array $delivery, string $signature): self
    {
        return new self('SISO_SELF_LOOP', "Self-loop: gate '" . $signature . "' returned its own type", $delivery);
    }

    public static function cycle(array $delivery, int $gateCount): self
    {
        $r = new self('SISO_CYCLE',
            "Cycle: '" . $delivery['type'] . "' claimed at depth " . $delivery['depth']
            . ' with ' . $gateCount . ' gates registered — a type repeated on this causal chain',
            $delivery);
        $r->depth = $delivery['depth'];
        return $r;
    }

    public static function gateThrew(array $delivery, string $signature, \Throwable $cause): self
    {
        $r = new self('SISO_GATE_THREW', "Gate '" . $signature . "' threw: " . self::thrownMessage($cause), $delivery, $cause);
        $r->cause = $cause;
        return $r;
    }

    public static function notEvent(?array $delivery, ?string $signature, string $what): self
    {
        if ($delivery === null) {
            return new self('SISO_NOT_EVENT', 'Not an Event: the entry door was given ' . $what, null);
        }
        return new self('SISO_NOT_EVENT',
            "Not an Event: gate '" . (string)$signature . "' returned " . $what
            . ' (a gate returns null, an Event or a list of Events)',
            $delivery);
    }

    private static function thrownMessage(\Throwable $t): string
    {
        try {
            return $t->getMessage();
        } catch (\Throwable) {
            return get_debug_type($t);
        }
    }
}
