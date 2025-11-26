<?php

namespace Helix\Routing;

use Helix\Http\Request;
use Helix\Http\Response;
use Helix\Core\Container\HelixContainer;
use Helix\Core\Exceptions\HttpException;
use Helix\Core\Exceptions\RouteNotFoundException;
use Helix\Core\Exceptions\MethodNotAllowedException;
use Helix\Core\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;
use JsonException;

class Router
{
    private array $routes = [
        'GET' => [], 'POST' => [], 'PUT' => [],
        'PATCH' => [], 'DELETE' => [], 'HEAD' => [], 'OPTIONS' => []
    ];

    private array $middlewares = [];
    private array $errorHandlers = [];
    private ?array $compiledRoutes = null;
    private ?string $currentGroupPrefix = null;
    private array $currentGroupMiddlewares = [];

    public function __construct(private HelixContainer $container)
    {
        $this->registerDefaultErrorHandlers();
    }

    /**
     * Create a route group with common prefix and middlewares
     */
    public function group(string $prefix, callable $callback, array $middlewares = []): void
    {
        $previousPrefix = $this->currentGroupPrefix ?? '';
        $previousMiddlewares = $this->currentGroupMiddlewares;

        $this->currentGroupPrefix = $previousPrefix . $this->normalizePath($prefix);
        $this->currentGroupMiddlewares = array_merge($previousMiddlewares, $middlewares);

        $callback($this);

        $this->currentGroupPrefix = $previousPrefix;
        $this->currentGroupMiddlewares = $previousMiddlewares;
    }

    /**
     * Add a route with the given HTTP method
     */
    public function add(string $method, string $path, array|callable $handler, array $middlewares = []): void
    {
        $method = strtoupper($method);
        $path = $this->currentGroupPrefix . $this->normalizePath($path);
        $middlewares = array_merge($this->currentGroupMiddlewares, $middlewares);

        $this->routes[$method][$path] = [
            'handler' => $handler,
            'middlewares' => $middlewares,
            'path' => $path,
            'method' => $method
        ];

        $this->compiledRoutes = null; // Reset compiled routes cache
    }

    // Convenience methods for common HTTP methods
    public function get(string $path, array|callable $handler, array $middlewares = []): void
    {
        $this->add('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, array|callable $handler, array $middlewares = []): void
    {
        $this->add('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, array|callable $handler, array $middlewares = []): void
    {
        $this->add('PUT', $path, $handler, $middlewares);
    }

    public function patch(string $path, array|callable $handler, array $middlewares = []): void
    {
        $this->add('PATCH', $path, $handler, $middlewares);
    }

    public function delete(string $path, array|callable $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $path, $handler, $middlewares);
    }

    /**
     * Add global middleware
     */
    public function middleware(string|callable $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /**
     * Register a custom error handler for a status code
     */
    public function errorHandler(int $statusCode, callable $handler): void
    {
        $this->errorHandlers[$statusCode] = $handler;
    }

    /**
     * Handle the incoming request
     */
    public function handle(Request $request): ResponseInterface
    {
        try {
            $route = $this->match($request);

            // Store matched route parameters in request
            foreach ($route['params'] ?? [] as $key => $value) {
                $request = $request->withAttribute($key, $value);
            }

            // Add route info to request attributes
            $request = $request->withAttribute('_route', [
                'path' => $route['path'],
                'method' => $route['method'],
                'handler' => $route['handler']
            ]);

            // Combine global and route-specific middlewares
            $middlewares = array_merge($this->middlewares, $route['middlewares']);

            // Create and process middleware pipeline
            $pipeline = new Pipeline($this->container, $middlewares);
            $pipeline->then(fn($request) => $this->callHandler($route['handler'], $request));
            $response = $pipeline->process($request);

            return $this->prepareResponse($response, $request);

        } catch (HttpException $e) {
            return $this->handleException($e);
        } catch (\Throwable $e) {
            return $this->handleException(
                new HttpException(500, 'Server Error', $e)
            );
        }
    }

    /**
     * Match request to a route
     */
    private function match(Request $request): array
    {
        $method = $request->getMethod();
        $path = $this->normalizePath($request->getUri()->getPath());

        // Check for exact match first
        if (isset($this->routes[$method][$path])) {
            return $this->routes[$method][$path];
        }

        // Check parameterized routes
        $compiledRoutes = $this->getCompiledRoutes();
        if (isset($compiledRoutes[$method])) {
            foreach ($compiledRoutes[$method] as $route) {
                if ($params = $this->matchCompiledRoute($route['pattern'], $path)) {
                    return array_merge($route, ['params' => $params]);
                }
            }
        }

        // Check for other allowed methods
        $allowedMethods = $this->getAllowedMethodsForPath($path);
        if (!empty($allowedMethods)) {
            throw new MethodNotAllowedException($allowedMethods);
        }

        throw new \Helix\Core\Exceptions\RouteNotFoundException("No route found for {$method} {$path}");
    }

    /**
     * Get allowed HTTP methods for a given path
     */
    private function getAllowedMethodsForPath(string $path): array
    {
        $allowedMethods = [];
        foreach ($this->routes as $method => $routes) {
            if (isset($routes[$path])) {
                $allowedMethods[] = $method;
            }
        }
        return $allowedMethods;
    }

    /**
     * Call the route handler
     */
    private function callHandler(array|callable $handler, Request $request): ResponseInterface
    {
        try {
            if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
                [$class, $method] = $handler;
                $instance = $this->container->get($class);
                $response = $this->container->call([$instance, $method], ['request' => $request]);
            } elseif (is_callable($handler)) {
                $response = $this->container->call($handler, ['request' => $request]);
            } else {
                throw new \RuntimeException('Invalid route handler');
            }

            return $this->ensureResponse($response);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException(500, 'Handler Error', $e);
        }
    }

    /**
     * Ensure the response is a valid ResponseInterface
     */
    private function ensureResponse($response): ResponseInterface
    {
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        if (is_array($response) || is_object($response)) {
            try {
                $json = json_encode($response, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                return new Response(200, ['Content-Type' => 'application/json'], $json);
            } catch (JsonException $e) {
                throw new HttpException(500, 'JSON Encoding Error', $e);
            }
        }

        return new Response(200, ['Content-Type' => 'text/html'], (string)$response);
    }

    /**
     * Prepare the final response
     */
    private function prepareResponse(ResponseInterface $response, Request $request): ResponseInterface
    {
        // Add default Content-Type if not set
        if (!$response->hasHeader('Content-Type')) {
            $response = $response->withHeader(
                'Content-Type',
                $this->negotiateContentType($request)
            );
        }

        // Add security headers
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-XSS-Protection', '1; mode=block');

        return $response;
    }

    /**
     * Handle exceptions and generate error responses
     */
    private function handleException(HttpException $e): ResponseInterface
    {
        $statusCode = $e->getCode();

        // Try custom error handler first
        if (isset($this->errorHandlers[$statusCode])) {
            try {
                $handler = $this->errorHandlers[$statusCode];
                $response = $this->container->call($handler, ['exception' => $e]);
                return $this->ensureResponse($response);
            } catch (\Exception $handlerException) {
                // Fall through to default handling
            }
        }

        // Default error response
        $data = [
            'error' => $e->getMessage(),
            'status' => $statusCode,
        ];

        if ($e->getPrevious()) {
            $data['details'] = $e->getPrevious()->getMessage();
        }

        try {
            $body = json_encode($data, JSON_THROW_ON_ERROR);
            return new Response(
                $statusCode,
                ['Content-Type' => 'application/json'],
                $body
            );
        } catch (JsonException $jsonException) {
            return new Response(
                500,
                ['Content-Type' => 'text/plain'],
                'Internal Server Error'
            );
        }
    }

    /**
     * Get compiled routes with pre-processed patterns
     */
    private function getCompiledRoutes(): array
    {
        if ($this->compiledRoutes !== null) {
            return $this->compiledRoutes;
        }

        $compiled = [];
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $path => $route) {
                $compiled[$method][] = [
                    'pattern' => $this->compileRoutePattern($path),
                    'handler' => $route['handler'],
                    'middlewares' => $route['middlewares'],
                    'path' => $path,
                    'method' => $method
                ];
            }
        }

        return $this->compiledRoutes = $compiled;
    }

    /**
     * Compile route path to regex pattern
     */
    private function compileRoutePattern(string $path): string
    {
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function ($matches) {
                $param = $matches[1];
                if (!ctype_alnum(str_replace('_', '', $param))) {
                    throw new \RuntimeException("Invalid parameter name: {$param}");
                }
                return "(?P<{$param}>[^/]+)";
            },
            $path
        );

        return "@^" . preg_replace('/\/+/', '/', $pattern) . "$@D";
    }

    /**
     * Match path against compiled route pattern
     */
    private function matchCompiledRoute(string $pattern, string $path): ?array
    {
        if (!preg_match($pattern, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Perform content negotiation
     */
    private function negotiateContentType(Request $request): string
    {
        $accept = $request->getHeaderLine('Accept');

        if (str_contains($accept, 'application/json')) {
            return 'application/json';
        }

        if (str_contains($accept, 'text/html')) {
            return 'text/html';
        }

        return 'text/plain';
    }

    /**
     * Normalize path by ensuring consistent formatting
     */
    private function normalizePath(string $path): string
    {
        $path = trim($path, '/');
        return $path === '' ? '/' : "/{$path}";
    }

    /**
     * Register default error handlers
     */
    private function registerDefaultErrorHandlers(): void
    {
        $this->errorHandler(400, function (HttpException $e) {
            return new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'Bad Request',
                'message' => $e->getMessage()
            ]));
        });

        $this->errorHandler(404, function (RouteNotFoundException $e) {
            return new Response(404, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'Not Found',
                'message' => $e->getMessage()
            ]));
        });

        $this->errorHandler(405, function (MethodNotAllowedException $e) {
            return new Response(405, [
                'Content-Type' => 'application/json',
                'Allow' => implode(', ', $e->getAllowedMethods())
            ], json_encode([
                'error' => 'Method Not Allowed',
                'allowed_methods' => $e->getAllowedMethods()
            ]));
        });

        $this->errorHandler(422, function (ValidationException $e) {
            return new Response(422, [
                'Content-Type' => 'application/json'
            ], json_encode([
                'error' => 'Validation Failed',
                'errors' => $e->getErrors()
            ]));
        });

        $this->errorHandler(500, function (HttpException $e) {
            return new Response(500, [
                'Content-Type' => 'application/json'
            ], json_encode([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ]));
        });
    }
}