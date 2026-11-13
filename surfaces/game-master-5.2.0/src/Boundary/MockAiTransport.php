<?php

namespace GameMaster\Boundary;

/**
 * MockAiTransport — a stand-in for the AI service.
 *
 * Used when no API key is configured (GAMEMASTER_MOCK_AI) and by
 * the scenario tests. It simulates the outside world, so the
 * little logic it has is test-double behaviour, not project
 * logic: scripted replies are handed out in order (cycling),
 * and without a script it answers every call with a plain,
 * deterministic narrative that echoes the player's action.
 *
 * Each reply reports 150 input / 350 output tokens (500 in all,
 * as the old MockAIGate did) under model "mock-ai-v1".
 */
final class MockAiTransport implements AiTransport
{
    private array $responses;
    private int $next = 0;
    private ?array $forcedError = null;
    private array $calls = [];

    /** @param array $responses strings, arrays (sent as JSON) or Closures (array $request): string */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function setResponses(array $responses): self
    {
        $this->responses = $responses;
        $this->next = 0;
        return $this;
    }

    public function forceError(string $code, string $message = ''): self
    {
        $this->forcedError = ['code' => $code, 'message' => $message !== '' ? $message : "Simulated error: {$code}"];
        return $this;
    }

    public function clearError(): self
    {
        $this->forcedError = null;
        return $this;
    }

    /** Every request received, in order. */
    public function getCalls(): array
    {
        return $this->calls;
    }

    public function call(array $request): array
    {
        $this->calls[] = $request;

        if ($this->forcedError !== null) {
            return ['ok' => false, 'content' => null, 'usage' => ['input' => 0, 'output' => 0],
                'model' => 'mock-ai-v1', 'error' => $this->forcedError];
        }

        if (!empty($this->responses)) {
            $response = $this->responses[$this->next % count($this->responses)];
            $this->next++;
            $content = $response instanceof \Closure ? (string) $response($request)
                : (is_array($response) ? json_encode($response, JSON_PRETTY_PRINT) : (string) $response);
        } else {
            $content = $this->defaultReply($request);
        }

        return ['ok' => true, 'content' => $content, 'usage' => ['input' => 150, 'output' => 350],
            'model' => 'mock-ai-v1', 'error' => null];
    }

    private function defaultReply(array $request): string
    {
        $action = 'wait';
        if (preg_match('/^PLAYER ACTION: (.*)$/m', $request['user'] ?? '', $m)) {
            $action = trim($m[1]);
        }
        return json_encode([
            'narrative' => "You {$action}. The world responds in kind.",
            'stateUpdates' => [],
            'suggestedActions' => ['Look around', 'Check inventory', 'Go to another area'],
        ], JSON_PRETTY_PRINT);
    }
}
