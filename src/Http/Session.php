<?php

namespace Helix\Http;

class Session
{
    private array $sessionConfig;

    public function __construct(array $config = [])
    {
        $this->sessionConfig = array_merge([
            'cookie_lifetime' => 0,
            'cookie_secure' => true,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
            'gc_maxlifetime' => 1440,
        ], $config);

        $this->startSession();
        $this->initializeFlash();
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params($this->sessionConfig);
            if ($this->sessionConfig['use_strict_mode']) {
                ini_set('session.use_strict_mode', '1');
            }
            session_start();
        }
    }

    private function initializeFlash(): void
    {
        $_SESSION['_flash'] ??= [];
        $_SESSION['_old_flash'] ??= [];

        // Remove flash from the previous request
        foreach ($_SESSION['_old_flash'] as $key => $_) {
            unset($_SESSION['_flash'][$key]);
        }

        // Promote current flash to old flash for this request
        $_SESSION['_old_flash'] = $_SESSION['_flash'];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->validateKey($key);
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->validateKey($key);
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        $this->validateKey($key);
        unset($_SESSION[$key]);
    }

    public function flash(string $key, mixed $value): void
    {
        $this->validateKey($key);
        $_SESSION['_flash'][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        return $_SESSION['_flash'][$key] ?? $default;
    }

    public function regenerate(): string
    {
        session_regenerate_id(true);
        return session_id();
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
    }

    public function getId(): string
    {
        return session_id();
    }

    public function getNamespace(string $namespace): SessionNamespace
    {
        return new SessionNamespace($this, $namespace);
    }

    private function validateKey(string $key): void
    {
        if (str_contains($key, '.')) {
            throw new \InvalidArgumentException("Session key cannot contain dots");
        }
        if (empty($key)) {
            throw new \InvalidArgumentException("Session key cannot be empty");
        }
    }
}