<?php

namespace GameMaster\Http;

/**
 * Request - Simple HTTP request wrapper
 * 
 * Extracts and validates request data from various sources.
 */
class Request
{
    private string $method;
    private array $query;
    private array $body;
    private array $headers;
    private string $path;
    
    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->query = $_GET ?? [];
        $this->headers = $this->parseHeaders();
        $this->path = $this->parsePath();
        $this->body = $this->parseBody();
    }
    
    /**
     * Get request method
     */
    public function getMethod(): string
    {
        return $this->method;
    }
    
    /**
     * Get request path
     */
    public function getPath(): string
    {
        return $this->path;
    }
    
    /**
     * Get query parameter
     */
    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }
    
    /**
     * Get all query parameters
     */
    public function allQuery(): array
    {
        return $this->query;
    }
    
    /**
     * Get body parameter
     */
    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $default;
    }
    
    /**
     * Get all body parameters
     */
    public function all(): array
    {
        return $this->body;
    }
    
    /**
     * Get header
     */
    public function header(string $key, $default = null): ?string
    {
        $key = strtolower($key);
        return $this->headers[$key] ?? $default;
    }
    
    /**
     * Check if request is JSON
     */
    public function isJson(): bool
    {
        $contentType = $this->header('content-type', '');
        return str_contains($contentType, 'application/json');
    }
    
    /**
     * Validate required fields
     */
    public function validate(array $rules): array
    {
        $errors = [];
        
        foreach ($rules as $field => $rule) {
            $value = $this->input($field);
            
            if (str_contains($rule, 'required') && empty($value)) {
                $errors[$field] = "{$field} is required";
                continue;
            }
            
            if (!empty($value)) {
                if (str_contains($rule, 'string') && !is_string($value)) {
                    $errors[$field] = "{$field} must be a string";
                }
                
                if (str_contains($rule, 'int') && !is_numeric($value)) {
                    $errors[$field] = "{$field} must be a number";
                }
                
                if (preg_match('/min:(\d+)/', $rule, $matches)) {
                    $min = (int)$matches[1];
                    if (is_string($value) && strlen($value) < $min) {
                        $errors[$field] = "{$field} must be at least {$min} characters";
                    }
                }
                
                if (preg_match('/max:(\d+)/', $rule, $matches)) {
                    $max = (int)$matches[1];
                    if (is_string($value) && strlen($value) > $max) {
                        $errors[$field] = "{$field} must be at most {$max} characters";
                    }
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Parse request headers
     */
    private function parseHeaders(): array
    {
        $headers = [];
        
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        
        // Content-Type and Content-Length don't have HTTP_ prefix
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        
        return $headers;
    }
    
    /**
     * Parse request path
     */
    private function parsePath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return rtrim($path, '/') ?: '/';
    }
    
    /**
     * Parse request body
     */
    private function parseBody(): array
    {
        if ($this->method === 'GET') {
            return $this->query;
        }
        
        $contentType = $this->header('content-type', '');
        
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return $_POST ?? [];
        }
        
        return $_POST ?? [];
    }
    
    /**
     * Create from array (for testing)
     */
    public static function fromArray(array $data): self
    {
        $request = new self();
        $request->method = $data['method'] ?? 'GET';
        $request->path = $data['path'] ?? '/';
        $request->query = $data['query'] ?? [];
        $request->body = $data['body'] ?? [];
        $request->headers = $data['headers'] ?? [];
        return $request;
    }
}
