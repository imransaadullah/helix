<?php

namespace Helix\Core\Exceptions;

// Base Exception (500)
class HttpException extends \RuntimeException 
{
    public function __construct(
        int $statusCode, 
        string $message = '', 
        ?\Throwable $previous = null,
        private array $headers = []
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getHeaders(): array 
    {
        return $this->headers;
    }
}

// 400 Bad Request
class BadRequestException extends HttpException 
{
    public function __construct(string $message = 'Bad Request', array $headers = []) 
    {
        parent::__construct(400, $message, null, $headers);
    }
}

// 401 Unauthorized 
class UnauthorizedException extends HttpException 
{
    public function __construct(string $message = 'Unauthorized', array $headers = []) 
    {
        parent::__construct(401, $message, null, $headers);
    }
}

// 403 Forbidden
class ForbiddenException extends HttpException 
{
    public function __construct(string $message = 'Forbidden', array $headers = []) 
    {
        parent::__construct(403, $message, null, $headers);
    }
}

// 404 Not Found
class RouteNotFoundException extends HttpException 
{
    public function __construct(string $message = 'Route Not Found', array $headers = []) 
    {
        parent::__construct(404, $message, null, $headers);
    }
}

// 405 Method Not Allowed
class MethodNotAllowedException extends HttpException 
{
    public function __construct(
        private array $allowedMethods,
        string $message = 'Method Not Allowed',
        array $headers = []
    ) {
        $headers['Allow'] = implode(', ', $allowedMethods);
        parent::__construct(405, $message, null, $headers);
    }

    public function getAllowedMethods(): array 
    {
        return $this->allowedMethods;
    }
}

// 419 CSRF Token Mismatch
class CsrfTokenException extends HttpException 
{
    public function __construct(string $message = 'CSRF Token Mismatch', array $headers = []) 
    {
        parent::__construct(419, $message, null, $headers);
    }
}

// 422 Validation Failed
class ValidationException extends HttpException 
{
    public function __construct(
        private array $errors,
        string $message = 'Validation Failed',
        array $headers = []
    ) {
        parent::__construct(422, $message, null, $headers);
    }

    public function getErrors(): array 
    {
        return $this->errors;
    }
}

// 429 Too Many Requests
class RateLimitException extends HttpException 
{
    public function __construct(
        int $retryAfter = 60,
        string $message = 'Too Many Requests',
        array $headers = []
    ) {
        $headers['Retry-After'] = (string) $retryAfter;
        parent::__construct(429, $message, null, $headers);
    }
}