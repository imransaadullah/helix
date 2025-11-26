<?php

namespace Helix\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class Response implements ResponseInterface
{
    private string $protocolVersion = '1.1';
    private array $headers = [];
    private StreamInterface $body;
    private int $statusCode;
    private string $reasonPhrase;

    private const STATUS_CODES = [
        // Informational 1xx
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        
        // Successful 2xx
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        
        // Redirection 3xx
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        
        // Client Errors 4xx
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        
        // Server Errors 5xx
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    public function __construct(
        int $status = 200,
        array $headers = [],
        $body = null,
        string $version = '1.1',
        ?string $reason = null
    ) {
        $this->statusCode = $this->filterStatus($status);
        $this->reasonPhrase = $reason ?? self::STATUS_CODES[$status] ?? '';
        $this->protocolVersion = $this->filterProtocolVersion($version);
        
        if ($body !== null && !$body instanceof StreamInterface) {
            $body = Stream::createFromString((string)$body);
        }
        $this->body = $body ?? Stream::createFromString('');
        
        $this->setHeaders($headers);
    }

    /* Factory Methods */
    
    public static function html(string $html, int $status = 200): self
    {
        return new self($status, [
            'Content-Type' => 'text/html; charset=UTF-8'
        ], $html);
    }

    public static function json($data, int $status = 200): self
    {
        return new self($status, [
            'Content-Type' => 'application/json; charset=UTF-8'
        ], json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT));
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, [
            'Location' => $url
        ]);
    }

    public static function file(string $path, ?string $filename = null): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("File not found: {$path}");
        }

        $filename = $filename ?? basename($path);
        $stream = Stream::createFromFile($path, 'r');

        return new self(200, [
            'Content-Type' => mime_content_type($path) ?: 'application/octet-stream',
            'Content-Length' => filesize($path),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Cache-Control' => 'must-revalidate',
            'Pragma' => 'public',
        ], $stream);
    }

    /* PSR-7 Interface Implementation */

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion($version): self
    {
        $version = $this->filterProtocolVersion($version);
        
        if ($this->protocolVersion === $version) {
            return $this;
        }

        $new = clone $this;
        $new->protocolVersion = $version;
        return $new;
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
        $name = strtolower($name);
        return $this->headers[$name] ?? [];
    }

    public function getHeaderLine($name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader($name, $value): self
    {
        $normalized = $this->normalizeHeaderName($name);
        $value = $this->normalizeHeaderValue($value);

        $new = clone $this;
        $new->headers[$normalized] = is_array($value) ? $value : [$value];
        return $new;
    }

    public function withAddedHeader($name, $value): self
    {
        $normalized = $this->normalizeHeaderName($name);
        $value = $this->normalizeHeaderValue($value);

        $new = clone $this;
        $new->headers[$normalized] = array_merge(
            $this->getHeader($name),
            is_array($value) ? $value : [$value]
        );
        return $new;
    }

    public function withoutHeader($name): self
    {
        $normalized = $this->normalizeHeaderName($name);
        
        if (!$this->hasHeader($normalized)) {
            return $this;
        }

        $new = clone $this;
        unset($new->headers[$normalized]);
        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): self
    {
        if ($body === $this->body) {
            return $this;
        }

        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus($code, $reasonPhrase = ''): self
    {
        $code = $this->filterStatus($code);
        $reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : (self::STATUS_CODES[$code] ?? '');

        if ($this->statusCode === $code && $this->reasonPhrase === $reasonPhrase) {
            return $this;
        }

        $new = clone $this;
        $new->statusCode = $code;
        $new->reasonPhrase = $reasonPhrase;
        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /* Enhanced Functionality */

    public function withCookie(
        string $name,
        string $value = "",
        int $expires = 0,
        string $path = "",
        string $domain = "",
        bool $secure = false,
        bool $httponly = false,
        string $samesite = ""
    ): self {
        $cookie = sprintf(
            '%s=%s',
            rawurlencode($name),
            rawurlencode($value)
        );

        if ($expires !== 0) {
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s T', $expires);
        }

        if ($path !== "") {
            $cookie .= '; Path=' . $path;
        }

        if ($domain !== "") {
            $cookie .= '; Domain=' . $domain;
        }

        if ($secure) {
            $cookie .= '; Secure';
        }

        if ($httponly) {
            $cookie .= '; HttpOnly';
        }

        if ($samesite !== "") {
            $cookie .= '; SameSite=' . $samesite;
        }

        return $this->withAddedHeader('Set-Cookie', $cookie);
    }

    public function withoutCookie(string $name): self
    {
        return $this->withCookie($name, '', 1);
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }

    public function isRedirect(): bool
    {
        return in_array($this->statusCode, [201, 301, 302, 303, 307, 308]);
    }

    public function isEmpty(): bool
    {
        return in_array($this->statusCode, [204, 205, 304]);
    }

    public function isOk(): bool
    {
        return $this->statusCode === 200;
    }

    public function isForbidden(): bool
    {
        return $this->statusCode === 403;
    }

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    public function send(): void
    {
        $this->sendHeaders();
        $this->sendBody();
    }

    /* Private Helpers */

    private function setHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            $normalized = $this->normalizeHeaderName($name);
            $this->headers[$normalized] = is_array($value) ? $value : [$value];
        }
    }

    private function normalizeHeaderName($name): string
    {
        return strtolower($name);
    }

    private function normalizeHeaderValue($value): array
    {
        return is_array($value) ? $value : [$value];
    }

    private function filterStatus(int $status): int
    {
        if ($status < 100 || $status >= 600) {
            throw new \InvalidArgumentException('Invalid HTTP status code');
        }
        return $status;
    }

    private function filterProtocolVersion(string $version): string
    {
        if (!in_array($version, ['1.0', '1.1', '2.0', '2'])) {
            throw new \InvalidArgumentException('Invalid HTTP protocol version');
        }
        return $version === '2' ? '2.0' : $version;
    }

    private function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        // Status line
        header(sprintf(
            'HTTP/%s %d %s',
            $this->protocolVersion,
            $this->statusCode,
            $this->reasonPhrase
        ), true, $this->statusCode);

        // Headers
        foreach ($this->headers as $name => $values) {
            $name = str_replace('-', ' ', $name);
            $name = ucwords($name);
            $name = str_replace(' ', '-', $name);
            
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }
    }

    private function sendBody(): void
    {
        if ($this->body->isSeekable()) {
            $this->body->rewind();
        }

        while (!$this->body->eof()) {
            echo $this->body->read(8192);
        }
    }
}