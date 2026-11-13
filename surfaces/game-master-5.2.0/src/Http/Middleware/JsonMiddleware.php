<?php

namespace GameMaster\Http\Middleware;

use GameMaster\Http\Request;
use GameMaster\Http\Response;

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
