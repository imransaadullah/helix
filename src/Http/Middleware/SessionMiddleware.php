<?php

namespace Helix\Middleware;

use Helix\Http\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Session $session,
        private array $options = []
    ) {
        $this->options = array_merge([
            'enable_csrf' => true,
            'csrf_token_key' => '_csrf_token',
            'regenerate_interval' => 300, // 5 minutes
            'last_regeneration_key' => '_last_regeneration',
        ], $options);
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $this->ensureSessionStarted();

        // Attach session to request attributes
        $request = $request->withAttribute('session', $this->session);

        // Handle CSRF protection if enabled
        if ($this->options['enable_csrf']) {
            $this->handleCsrfProtection($request);
        }

        // Regenerate session if needed
        $this->handleSessionRegeneration();

        // Handle request
        $response = $handler->handle($request);

        // Persist session
        $this->persistSession();

        return $response;
    }

    private function ensureSessionStarted(): void
    {
        // Placeholder for future session startup logic if needed
    }

    private function handleCsrfProtection(ServerRequestInterface $request): void
    {
        $method = $request->getMethod();
        $tokenKey = $this->options['csrf_token_key'];

        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            $token = $request->getParsedBody()[$tokenKey] ??
                     $request->getHeaderLine('X-CSRF-Token');

            if (
                !$this->session->has($tokenKey) ||
                !hash_equals($this->session->get($tokenKey), $token)
            ) {
                // Log or trigger an event if needed
                throw new \RuntimeException('Invalid CSRF token');
            }
        }
    }

    private function handleSessionRegeneration(): void
    {
        $key = $this->options['last_regeneration_key'];
        $lastRegeneration = (int) $this->session->get($key, 0);
        $currentTime = time();

        if (($currentTime - $lastRegeneration) > $this->options['regenerate_interval']) {
            $this->session->regenerate();
            $this->session->set($key, $currentTime);

            // Optional logging
            error_log("Session regenerated at " . date('c'));
        }
    }

    private function persistSession(): void
    {
        // Placeholder for custom session persistence logic if needed
    }
}
