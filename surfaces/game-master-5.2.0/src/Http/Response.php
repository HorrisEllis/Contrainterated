<?php

namespace GameMaster\Http;

/**
 * Response - Simple HTTP response builder
 * 
 * Provides a fluent interface for building HTTP responses.
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private $body = null;
    private bool $sent = false;
    
    /**
     * Set status code
     */
    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }
    
    /**
     * Set header
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }
    
    /**
     * Set multiple headers
     */
    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
        return $this;
    }
    
    /**
     * Set JSON body
     */
    public function json($data, int $flags = 0): self
    {
        $this->header('Content-Type', 'application/json');
        $this->body = json_encode($data, $flags | JSON_UNESCAPED_UNICODE);
        return $this;
    }
    
    /**
     * Set plain text body
     */
    public function text(string $content): self
    {
        $this->header('Content-Type', 'text/plain');
        $this->body = $content;
        return $this;
    }
    
    /**
     * Set HTML body
     */
    public function html(string $content): self
    {
        $this->header('Content-Type', 'text/html; charset=utf-8');
        $this->body = $content;
        return $this;
    }
    
    /**
     * Send the response
     */
    public function send(): void
    {
        if ($this->sent) {
            return;
        }
        
        $this->sent = true;
        
        // Set status code
        http_response_code($this->statusCode);
        
        // Set headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        
        // Send body
        if ($this->body !== null) {
            echo $this->body;
        }
    }
    
    /**
     * Get response data (for testing)
     */
    public function getData(): array
    {
        return [
            'status' => $this->statusCode,
            'headers' => $this->headers,
            'body' => $this->body,
        ];
    }
    
    // ========== Static Helpers ==========
    
    /**
     * Create success response
     */
    public static function success($data = null, string $message = 'Success'): self
    {
        $response = new self();
        return $response->status(200)->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }
    
    /**
     * Create error response
     */
    public static function error(string $message, int $code = 400, array $errors = []): self
    {
        $response = new self();
        $data = [
            'success' => false,
            'error' => $message,
        ];
        
        if (!empty($errors)) {
            $data['errors'] = $errors;
        }
        
        return $response->status($code)->json($data);
    }
    
    /**
     * Create not found response
     */
    public static function notFound(string $message = 'Not found'): self
    {
        return self::error($message, 404);
    }
    
    /**
     * Create validation error response
     */
    public static function validationError(array $errors): self
    {
        return self::error('Validation failed', 422, $errors);
    }
    
    /**
     * Create server error response
     */
    public static function serverError(string $message = 'Internal server error'): self
    {
        return self::error($message, 500);
    }
    
    /**
     * Create created response
     */
    public static function created($data = null): self
    {
        $response = new self();
        return $response->status(201)->json([
            'success' => true,
            'data' => $data,
        ]);
    }
    
    /**
     * Create no content response
     */
    public static function noContent(): self
    {
        $response = new self();
        return $response->status(204);
    }
}
