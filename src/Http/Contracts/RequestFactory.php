<?php

namespace Helix\Http\Contracts;

use Helix\Http\Request;
use Helix\Http\Stream;
use Helix\Http\Uri;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use InvalidArgumentException;

class RequestFactory implements RequestFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        $method = $this->validateMethod($method);
        
        if (is_string($uri)) {
            try {
                $uri = new Uri($uri);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException("Invalid URI provided", 0, $e);
            }
        }
        
        if (!$uri instanceof UriInterface) {
            throw new InvalidArgumentException(
                'URI must be a string or instance of ' . UriInterface::class
            );
        }
        
        return new Request([
            'method' => $method,
            'uri' => $uri,
        ]);
    }

    public function get($uri, array $headers = []): Request
    {
        return new Request([
            'method' => 'GET',
            'uri' => $this->ensureUri($uri),
            'headers' => $headers,
        ]);
    }

    public function post($uri, $body = null, array $headers = []): Request
    {
        $request = [
            'method' => 'POST',
            'uri' => $this->ensureUri($uri),
            'headers' => $headers,
        ];

        if ($body !== null) {
            $request['body'] = is_string($body) ? $body : json_encode($body);
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'text/plain';
        }

        return new Request($request);
    }

    public function put($uri, $body = null, array $headers = []): Request
    {
        $request = [
            'method' => 'PUT',
            'uri' => $this->ensureUri($uri),
            'headers' => $headers,
        ];

        if ($body !== null) {
            $request['body'] = is_string($body) ? $body : json_encode($body);
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'text/plain';
        }

        return new Request($request);
    }

    public function delete($uri, array $headers = []): Request
    {
        return new Request([
            'method' => 'DELETE',
            'uri' => $this->ensureUri($uri),
            'headers' => $headers,
        ]);
    }

    public function patch($uri, $body = null, array $headers = []): Request
    {
        $request = [
            'method' => 'PATCH',
            'uri' => $this->ensureUri($uri),
            'headers' => $headers,
        ];

        if ($body !== null) {
            $request['body'] = is_string($body) ? $body : json_encode($body);
            $headers['Content-Type'] = $headers['Content-Type'] ?? 'text/plain';
        }

        return new Request($request);
    }

    public function json(string $method, $uri, $data = null, array $headers = []): Request
    {
        $request = [
            'method' => $this->validateMethod($method),
            'uri' => $this->ensureUri($uri),
            'headers' => array_merge($headers, ['Content-Type' => 'application/json']),
        ];

        if ($data !== null) {
            $request['body'] = json_encode($data, JSON_THROW_ON_ERROR);
        }

        return new Request($request);
    }

    public function form(string $method, $uri, array $data = [], array $headers = []): Request
    {
        $request = [
            'method' => $this->validateMethod($method),
            'uri' => $this->ensureUri($uri),
            'headers' => array_merge($headers, [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]),
        ];

        if (!empty($data)) {
            $request['body'] = http_build_query($data);
        }

        return new Request($request);
    }

    private function ensureUri($uri): UriInterface
    {
        if (is_string($uri)) {
            return new Uri($uri);
        }

        if ($uri instanceof UriInterface) {
            return $uri;
        }

        throw new InvalidArgumentException(
            'URI must be a string or instance of ' . UriInterface::class
        );
    }

    private function validateMethod(string $method): string
    {
        $method = strtoupper($method);
        $validMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
        
        if (!in_array($method, $validMethods)) {
            throw new InvalidArgumentException("Invalid HTTP method: {$method}");
        }
        
        return $method;
    }
}