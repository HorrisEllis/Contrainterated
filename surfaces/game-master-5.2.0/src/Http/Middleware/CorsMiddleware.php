<?php

namespace GameMaster\Http\Middleware;

use GameMaster\Http\Request;
use GameMaster\Http\Response;

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
