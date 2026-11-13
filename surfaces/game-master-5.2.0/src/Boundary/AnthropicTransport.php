<?php

namespace GameMaster\Boundary;

use GameMaster\Logger;

/**
 * AnthropicTransport — the Messages API over cURL.
 *
 * Boundary code (formerly the I/O half of AIGenerateGate).
 * Transient failures are retried here, with the same linear
 * backoff as before (0.5 s, 1 s, …): retrying a network call is
 * transport behaviour, invisible to the stream.
 */
final class AnthropicTransport implements AiTransport
{
    public function __construct(
        private readonly string $endpoint = 'https://api.anthropic.com/v1/messages',
        private readonly string $apiKey = '',
        private readonly string $model = 'claude-sonnet-4-20250514',
        private readonly int $maxTokens = 2048,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxRetries = 2,
    ) {
    }

    public function call(array $request): array
    {
        if ($this->apiKey === '') {
            return $this->failure('AI_NOT_CONFIGURED', 'AI API key not configured');
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= max(1, $this->maxRetries); $attempt++) {
            try {
                $r = $this->post($request['system'] ?? '', $request['user'] ?? '');
                return [
                    'ok' => true,
                    'content' => $r['content'],
                    'usage' => ['input' => $r['input'], 'output' => $r['output']],
                    'model' => $this->model,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Logger::warn("AI call attempt {$attempt} failed", ['error' => $lastError, 'maxRetries' => $this->maxRetries]);
                if ($attempt < $this->maxRetries) {
                    usleep($attempt * 500000);
                }
            }
        }

        return $this->failure('AI_REQUEST_FAILED', $lastError ?? "AI API request failed after {$this->maxRetries} attempts");
    }

    private function failure(string $code, string $message): array
    {
        return [
            'ok' => false,
            'content' => null,
            'usage' => ['input' => 0, 'output' => 0],
            'model' => $this->model,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    /** @return array{content: string, input: int, output: int} */
    private function post(string $system, string $user): array
    {
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ]),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException("cURL error: {$curlError}");
        }
        if ($httpCode === 429) {
            throw new \RuntimeException('Rate limited (429)');
        }
        if ($httpCode === 529) {
            throw new \RuntimeException('API overloaded (529)');
        }
        if ($httpCode !== 200) {
            $body = json_decode((string) $response, true);
            throw new \RuntimeException('API error: ' . ($body['error']['message'] ?? "HTTP {$httpCode}"));
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response');
        }
        $content = $decoded['content'][0]['text'] ?? null;
        if ($content === null) {
            throw new \RuntimeException('No content in response');
        }

        return [
            'content' => $content,
            'input' => (int) ($decoded['usage']['input_tokens'] ?? 0),
            'output' => (int) ($decoded['usage']['output_tokens'] ?? 0),
        ];
    }
}
