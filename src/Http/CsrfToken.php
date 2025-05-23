<?php

namespace Helix\Http;

use RuntimeException;
use InvalidArgumentException;

class CsrfToken
{
    private Session $session;
    private string $sessionKey;
    private int $tokenLength;
    private ?int $expiration;

    public function __construct(
        Session $session,
        string $sessionKey = '_csrf',
        int $tokenLength = 32,
        ?int $expiration = 3600 // 1 hour in seconds
    ) {
        if ($tokenLength < 16) {
            throw new InvalidArgumentException('Token length must be at least 16 bytes');
        }

        $this->session = $session;
        $this->sessionKey = $sessionKey;
        $this->tokenLength = $tokenLength;
        $this->expiration = $expiration;
    }

    public function getToken(string $name = 'default'): string
    {
        $tokens = $this->getTokenStorage();
        
        if (!isset($tokens[$name]) || $this->isTokenExpired($tokens[$name])) {
            $tokens[$name] = $this->createNewTokenData();
            $this->updateTokenStorage($tokens);
        }

        return $tokens[$name]['token'];
    }

    public function validate(string $token, string $name = 'default'): bool
    {
        if (empty($token)) {
            return false;
        }

        $tokens = $this->getTokenStorage();
        
        if (!isset($tokens[$name])) {
            return false;
        }

        $tokenData = $tokens[$name];

        if ($this->isTokenExpired($tokenData)) {
            $this->removeToken($name);
            return false;
        }

        return hash_equals($tokenData['token'], $token);
    }

    public function regenerate(string $name = 'default'): void
    {
        $tokens = $this->getTokenStorage();
        $tokens[$name] = $this->createNewTokenData();
        $this->updateTokenStorage($tokens);
    }

    public function removeToken(string $name = 'default'): void
    {
        $tokens = $this->getTokenStorage();
        unset($tokens[$name]);
        $this->updateTokenStorage($tokens);
    }

    public function clearAll(): void
    {
        $this->session->remove($this->sessionKey);
    }

    private function createNewTokenData(): array
    {
        return [
            'token' => bin2hex(random_bytes($this->tokenLength)),
            'created_at' => time()
        ];
    }

    private function getTokenStorage(): array
    {
        return $this->session->get($this->sessionKey, []);
    }

    private function updateTokenStorage(array $tokens): void
    {
        $this->session->set($this->sessionKey, $tokens);
    }

    private function isTokenExpired(array $tokenData): bool
    {
        if ($this->expiration === null) {
            return false;
        }

        return (time() - $tokenData['created_at']) > $this->expiration;
    }
}