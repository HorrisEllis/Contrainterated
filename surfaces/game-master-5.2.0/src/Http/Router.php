<?php

namespace GameMaster\Http;

use GameMaster\Logger;

/**
 * Router - Simple HTTP router
 *
 * Routes requests to handlers based on path patterns.
 */
class Router
{
    private array $routes = [];
    private array $middleware = [];
    private ?string $prefix = null;
    
    /**
     * Add middleware
     */
    public function use(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }
    
    /**
     * Set route prefix
     */
    public function prefix(string $prefix): self
    {
        $this->prefix = '/' . trim($prefix, '/');
        return $this;
    }
    
    /**
     * Register GET route
     */
    public function get(string $path, callable $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }
    
    /**
     * Register POST route
     */
    public function post(string $path, callable $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }
    
    /**
     * Register PUT route
     */
    public function put(string $path, callable $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }
    
    /**
     * Register DELETE route
     */
    public function delete(string $path, callable $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }
    
    /**
     * Register route for any method
     */
    public function any(string $path, callable $handler): self
    {
        return $this->addRoute('*', $path, $handler);
    }
    
    /**
     * Add route
     */
    private function addRoute(string $method, string $path, callable $handler): self
    {
        $fullPath = $this->prefix ? $this->prefix . $path : $path;
        $fullPath = '/' . trim($fullPath, '/');
        
        // Convert path params to regex
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $fullPath);
        $pattern = '#^' . $pattern . '$#';
        
        $this->routes[] = [
            'method' => $method,
            'path' => $fullPath,
            'pattern' => $pattern,
            'handler' => $handler,
        ];
        
        return $this;
    }
    
    /**
     * Dispatch request
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getPath();
        
        // Run middleware
        foreach ($this->middleware as $middleware) {
            $result = $middleware($request);
            if ($result instanceof Response) {
                return $result;
            }
        }
        
        // Find matching route
        foreach ($this->routes as $route) {
            if ($route['method'] !== '*' && $route['method'] !== $method) {
                continue;
            }
            
            if (preg_match($route['pattern'], $path, $matches)) {
                // Extract named params
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                
                try {
                    return $route['handler']($request, ...array_values($params));
                } catch (\Throwable $e) {
                    Logger::exception($e, 'Router dispatch ' . $route['method'] . ' ' . $route['path']);
                    return Response::serverError($e->getMessage());
                }
            }
        }
        
        // No route matched
        Logger::warn('No route matched', ['method' => $method, 'path' => $path]);
        return Response::notFound("Route not found: {$method} {$path}");
    }
    
    /**
     * Run the router (dispatch and send response)
     */
    public function run(?Request $request = null): void
    {
        $request = $request ?? new Request();
        $response = $this->dispatch($request);
        $response->send();
    }
    
    /**
     * Get registered routes (for debugging)
     */
    public function getRoutes(): array
    {
        return array_map(function ($route) {
            return [
                'method' => $route['method'],
                'path' => $route['path'],
            ];
        }, $this->routes);
    }
}
