<?php

namespace GameMaster\Http\Middleware;

use GameMaster\Http\Request;
use GameMaster\Http\Response;
use GameMaster\Logger;

/**
 * ErrorMiddleware - Catches exceptions and formats error responses
 */
class ErrorMiddleware
{
    private bool $debug;
    
    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }
    
    public function __invoke(Request $request): ?Response
    {
        // Set error handler for this request
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        
        return null; // Continue to next middleware/handler
    }
    
    /**
     * Handle exception (call after catching)
     */
    public function handleException(\Throwable $e): Response
    {
        Logger::exception($e, 'ErrorMiddleware');

        $statusCode = 500;
        $message = 'Internal server error';
        
        // Map specific exceptions to status codes
        if ($e instanceof \InvalidArgumentException) {
            $statusCode = 400;
            $message = $e->getMessage();
        } elseif ($e instanceof \RuntimeException) {
            $message = $e->getMessage();
        }
        
        $response = [
            'success' => false,
            'error' => $message,
        ];
        
        if ($this->debug) {
            $response['debug'] = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ];
        }
        
        return (new Response())->status($statusCode)->json($response);
    }
}

/**
 * CorsMiddleware - Handles Cross-Origin Resource Sharing
 */
class CorsMiddleware
{
    private array $allowedOrigins;
    private array $allowedMethods;
    private array $allowedHeaders;
    private bool $allowCredentials;
    private int $maxAge;
    
    public function __construct(array $config = [])
    {
        $this->allowedOrigins = $config['origins'] ?? ['*'];
        $this->allowedMethods = $config['methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'];
        $this->allowedHeaders = $config['headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With'];
        $this->allowCredentials = $config['credentials'] ?? false;
        $this->maxAge = $config['maxAge'] ?? 86400;
    }
    
    public function __invoke(Request $request): ?Response
    {
        $origin = $request->header('origin');
        
        // Check if origin is allowed
        $allowedOrigin = $this->getAllowedOrigin($origin);
        
        if ($allowedOrigin) {
            header("Access-Control-Allow-Origin: {$allowedOrigin}");
        }
        
        if ($this->allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }
        
        // Handle preflight request
        if ($request->getMethod() === 'OPTIONS') {
            header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
            header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
            header('Access-Control-Max-Age: ' . $this->maxAge);
            
            return (new Response())->status(204);
        }
        
        return null; // Continue to next middleware/handler
    }
    
    private function getAllowedOrigin(?string $origin): ?string
    {
        if ($origin === null) {
            return null;
        }
        
        if (in_array('*', $this->allowedOrigins)) {
            return '*';
        }
        
        if (in_array($origin, $this->allowedOrigins)) {
            return $origin;
        }
        
        return null;
    }
}

/**
 * JsonMiddleware - Ensures JSON content type for API requests
 */
class JsonMiddleware
{
    public function __invoke(Request $request): ?Response
    {
        // Only check POST/PUT/PATCH requests with body
        $method = $request->getMethod();
        
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $contentType = $request->header('content-type', '');
            
            // Allow empty body or JSON
            if (!empty(file_get_contents('php://input')) && !str_contains($contentType, 'application/json')) {
                return Response::error('Content-Type must be application/json', 415);
            }
        }
        
        return null;
    }
}

/**
 * RateLimitMiddleware - Simple rate limiting
 */
class RateLimitMiddleware
{
    private int $maxRequests;
    private int $windowSeconds;
    private string $storagePath;
    
    public function __construct(int $maxRequests = 60, int $windowSeconds = 60, string $storagePath = '/tmp')
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->storagePath = $storagePath;
    }
    
    public function __invoke(Request $request): ?Response
    {
        $identifier = $this->getIdentifier($request);
        $key = md5($identifier);
        $file = "{$this->storagePath}/ratelimit_{$key}.json";
        
        $data = $this->loadData($file);
        $now = time();
        
        // Clean old entries
        $data['requests'] = array_filter(
            $data['requests'] ?? [],
            fn($timestamp) => $timestamp > ($now - $this->windowSeconds)
        );
        
        // Check limit
        if (count($data['requests']) >= $this->maxRequests) {
            $retryAfter = min($data['requests']) + $this->windowSeconds - $now;
            
            return (new Response())
                ->status(429)
                ->header('Retry-After', (string)$retryAfter)
                ->json([
                    'success' => false,
                    'error' => 'Too many requests',
                    'retryAfter' => $retryAfter,
                ]);
        }
        
        // Add current request
        $data['requests'][] = $now;
        $this->saveData($file, $data);
        
        return null;
    }
    
    private function getIdentifier(Request $request): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    private function loadData(string $file): array
    {
        if (!file_exists($file)) {
            return ['requests' => []];
        }
        
        $content = @file_get_contents($file);
        return $content ? (json_decode($content, true) ?? ['requests' => []]) : ['requests' => []];
    }
    
    private function saveData(string $file, array $data): void
    {
        @file_put_contents($file, json_encode($data));
    }
}

/**
 * LoggingMiddleware - Logs requests via Logger
 */
class LoggingMiddleware
{
    public function __invoke(Request $request): ?Response
    {
        // Request is already logged in api.php before the router runs.
        // This middleware exists as a hook point for per-route logic if needed.
        return null;
    }
}
