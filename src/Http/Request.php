<?php

namespace Helix\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Helix\Http\UploadedFile;
use Helix\Http\Session;
use Helix\Http\CsrfToken;

class Request implements ServerRequestInterface
{
    private array $attributes = [];
    private array $cookieParams;
    private array $queryParams;
    private array $serverParams;
    private array $uploadedFiles;
    private array $parsedBody;
    private array $headers;
    private string $method;
    private UriInterface $uri;
    private string $protocolVersion = '1.1';
    private StreamInterface $body;
    private ?Session $session = null;
    private ?CsrfToken $csrfToken = null;
    private ?string $requestTarget = null;

    public function __construct(
        array $serverParams = [],
        array $uploadedFiles = [],
        array $cookieParams = [],
        array $queryParams = [],
        array $parsedBody = [],
        ?StreamInterface $body = null
    ) {
        $this->serverParams = $serverParams ?: $_SERVER;
        $this->uploadedFiles = $this->normalizeFiles($uploadedFiles ?: $_FILES);
        $this->cookieParams = $cookieParams ?: $_COOKIE;
        $this->queryParams = $queryParams ?: $_GET;
        $this->headers = $this->extractHeaders();
        $this->body = $body ?? $this->createStreamFromInput();
        $this->method = strtoupper($this->serverParams['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $this->createUri();
        $this->parsedBody = $this->parseBody($parsedBody);
    }

    /* PSR-7 Methods */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($version): self
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader($name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader($name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine($name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader($name, $value): self
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = (array) $value;
        return $clone;
    }

    public function withAddedHeader($name, $value): self
    {
        $clone = clone $this;
        $name = strtolower($name);
        $clone->headers[$name] = array_merge($clone->headers[$name] ?? [], (array) $value);
        return $clone;
    }

    public function withoutHeader($name): self
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): self
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function getRequestTarget(): string
    {
        return $this->requestTarget ?? $this->uri->getPath() . ($this->uri->getQuery() ? '?' . $this->uri->getQuery() : '');
    }

    public function withRequestTarget($requestTarget): self
    {
        $clone = clone $this;
        $clone->requestTarget = $requestTarget;
        return $clone;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod($method): self
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);
        return $clone;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, $preserveHost = false): self
    {
        $clone = clone $this;
        $clone->uri = $uri;
        if (!$preserveHost) {
            $host = $uri->getHost();
            if ($host) {
                $clone->headers['host'] = [$host];
            }
        }
        return $clone;
    }

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function withCookieParams(array $cookies): self
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;
        return $clone;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query): self
    {
        $clone = clone $this;
        $clone->queryParams = $query;
        return $clone;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $uploadedFiles): self
    {
        $clone = clone $this;
        $clone->uploadedFiles = $this->normalizeFiles($uploadedFiles);
        return $clone;
    }

    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): self
    {
        $clone = clone $this;
        $clone->parsedBody = $data;
        return $clone;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute($name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute($name, $value): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    public function withoutAttribute($name): self
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);
        return $clone;
    }

    /* Custom Enhancements */

    // Convenience method to get input data
    public function input(string $key, $default = null)
    {
        $data = array_merge($this->queryParams, $this->parsedBody);
        return $this->getNestedValue($data, $key, $default);
    }

    private function getNestedValue(array $data, string $key, $default)
    {
        foreach (explode('.', $key) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                return $default;
            }
        }
        return $data;
    }

    public function file(string $key): ?UploadedFileInterface
    {
        return $this->uploadedFiles[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->uploadedFiles[$key]) && $this->uploadedFiles[$key]->getError() !== UPLOAD_ERR_NO_FILE;
    }

    public function validateFile(string $key, array $allowedMimeTypes = [], int $maxSize = 0, bool $isImage = false): bool
    {
        if (!$this->hasFile($key)) {
            return false;
        }

        $file = $this->file($key);

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return false;
        }

        if ($maxSize > 0 && $file->getSize() > $maxSize) {
            return false;
        }

        if (!empty($allowedMimeTypes) && !in_array($file->getClientMediaType(), $allowedMimeTypes)) {
            return false;
        }

        if ($isImage) {
            $imageInfo = @getimagesize($file->getStream()->getMetadata('uri'));
            if ($imageInfo === false) {
                return false;
            }
        }

        return true;
    }

    public function csrfToken(): CsrfToken
    {
        if ($this->csrfToken === null) {
            $this->csrfToken = new CsrfToken($this->session());
        }
        return $this->csrfToken;
    }

    public function validateCsrfToken(?string $token = null): bool
    {
        $token = $token ?? $this->parsedBody['_csrf'] ?? $this->headers['x-csrf-token'][0] ?? null;
        return $this->csrfToken()->validate($token);
    }

    public function session(): Session
    {
        if ($this->session === null) {
            $this->session = new Session();
        }
        return $this->session;
    }

    public function validate(array $rules): array
    {
        $validator = new RequestValidator($this);
        return $validator->validate($rules);
    }

    public function isJson(): bool
    {
        return str_contains($this->getHeaderLine('content-type'), 'application/json');
    }

    public function wantsJson(): bool
    {
        return str_contains($this->getHeaderLine('accept'), 'application/json');
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function ip(): ?string
    {
        return $this->serverParams['HTTP_CLIENT_IP']
            ?? $this->serverParams['HTTP_X_FORWARDED_FOR']
            ?? $this->serverParams['REMOTE_ADDR']
            ?? null;
    }

    /* Helpers */

    private function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFileInterface) {
                $normalized[$key] = $file;
            } elseif (is_array($file) && isset($file['tmp_name'])) {
                $normalized[$key] = new UploadedFile(
                    $file['tmp_name'],
                    $file['size'],
                    $file['error'],
                    $file['name'],
                    $file['type']
                );
            } elseif (is_array($file)) {
                $normalized[$key] = $this->normalizeFiles($file);
            }
        }

        return $normalized;
    }

    private function extractHeaders(): array
    {
        $headers = [];

        // Try getallheaders() first (works in Apache/nginx with PHP-FPM)
        if (function_exists('getallheaders')) {
            $headers = getallheaders() ?: [];
        }

        // Extract headers from $_SERVER (works in CLI/testing)
        foreach ($this->serverParams as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                // Convert HTTP_CONTENT_TYPE to Content-Type
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[$headerName] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'])) {
                // These special headers don't have HTTP_ prefix
                $headerName = str_replace('_', '-', $key);
                $headers[$headerName] = $value;
            }
        }

        return $this->normalizeHeaders($headers);
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = is_array($value) ? $value : [$value];
        }
        return $normalized;
    }

    private function parseBody(array $parsedBody): array
    {
        if (!empty($parsedBody)) {
            return $parsedBody;
        }

        if ($this->isJson()) {
            $data = json_decode((string)$this->body, true);
            return is_array($data) ? $data : [];
        }

        if ($this->isMethod('POST') && empty($_POST)) {
            parse_str((string)$this->body, $data);
            return is_array($data) ? $data : [];
        }

        return $_POST;
    }

    private function createStreamFromInput(): StreamInterface
    {
        // In CLI/test environments, php://input may block. Use memory stream instead.
        if (PHP_SAPI === 'cli') {
            return Stream::createFromString('');
        }
        return new Stream(fopen('php://input', 'r'));
    }

    private function createUri(): UriInterface
    {
        $requestUri = $this->serverParams['REQUEST_URI'] ?? '/';

        // Split REQUEST_URI into path and query string
        $path = $requestUri;
        $queryString = '';

        if (($pos = strpos($requestUri, '?')) !== false) {
            $path = substr($requestUri, 0, $pos);
            $queryString = substr($requestUri, $pos + 1);
        }

        // Use QUERY_STRING if available (more reliable), otherwise use extracted query
        $queryString = $this->serverParams['QUERY_STRING'] ?? $queryString;

        return new Uri(
            ($this->serverParams['HTTPS'] ?? 'off') === 'on' ? 'https' : 'http',
            $this->serverParams['HTTP_HOST'] ?? 'localhost',
            $this->serverParams['SERVER_PORT'] ?? null,
            $path,
            $queryString
        );
    }
}
