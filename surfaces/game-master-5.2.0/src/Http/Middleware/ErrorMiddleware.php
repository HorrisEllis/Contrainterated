<?php

namespace GameMaster\Http\Middleware;

use GameMaster\Http\Request;
use GameMaster\Http\Response;

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
