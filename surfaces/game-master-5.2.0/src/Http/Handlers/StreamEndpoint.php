<?php

namespace GameMaster\Http\Handlers;

use GameMaster\Boundary\AiTransport;
use GameMaster\GameStreamFactory;
use GameMaster\Http\Response;
use GameMaster\Logger;
use Siso\Core\Event;
use Siso\Core\Rejection;
use Siso\Observer\StreamLog;

/**
 * StreamEndpoint — what every handler does: turn the HTTP
 * request into one entry event, let the driver run the stream,
 * and send back the http_response event the gates produced.
 *
 * Handlers hold no game logic. Validation, status codes and
 * response bodies are decided by gates.
 */
abstract class StreamEndpoint
{
    public function __construct(
        protected readonly GameStreamFactory $factory,
        protected readonly bool $debug = false,
        protected readonly ?AiTransport $transport = null,
    ) {
    }

    /** The boundary's clock and entropy, supplied to gates on entry events. */
    protected static function now(): string
    {
        return date('c');
    }

    protected static function nonce(): string
    {
        return bin2hex(random_bytes(8));
    }

    protected function respond(Event $entry, string $label): Response
    {
        $log = $this->debug ? new StreamLog('EVENTS') : null;
        try {
            $settled = $this->factory->createDriver($this->transport, $log)->run($entry);
            foreach ($settled as $event) {
                if ($event->type === 'http_response') {
                    return (new Response())->status($event->data['status'])->json($event->data['body']);
                }
            }
            Logger::error("{$label}: stream settled without an http_response", [
                'settled' => array_map(fn(Event $e) => $e->type, $settled),
            ]);
            $body = ['success' => false, 'error' => 'Processing failed - no result'];
        } catch (Rejection $r) {
            // A gate threw, looped or returned something that is not an
            // event (core K5): the propagation committed nothing.
            Logger::error("{$label}: propagation rejected ({$r->code})", [
                'message' => $r->message, 'eventType' => $r->eventType,
                'cause' => $r->cause?->getMessage(),
            ]);
            $body = ['success' => false, 'error' => 'An error occurred while processing the request'];
            if ($this->debug) {
                $body['debug'] = ['rejection' => $r->code, 'message' => $r->message];
            }
        } catch (\Throwable $e) {
            Logger::exception($e, $label);
            $body = ['success' => false, 'error' => 'An error occurred while processing the request'];
            if ($this->debug) {
                $body['debug'] = ['exception' => $e->getMessage()];
            }
        }
        if ($log !== null) {
            $body['debug']['history'] = $log->sample()['entries'];
        }
        return Response::serverError($body['error'])->json($body);
    }

    /** Test helper: decode a response body. */
    protected static function decode(Response $response): array
    {
        return json_decode($response->getData()['body'] ?? '', true) ?? [];
    }
}
