<?php

namespace Helix\Http;

class SessionNamespace
{
    public function __construct(
        private Session $session,
        private string $namespace
    ) {
        if (empty($namespace)) {
            throw new \InvalidArgumentException("Namespace cannot be empty");
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->get("{$this->namespace}.{$key}", $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->session->set("{$this->namespace}.{$key}", $value);
    }

    public function has(string $key): bool
    {
        return $this->session->has("{$this->namespace}.{$key}");
    }

    public function remove(string $key): void
    {
        $this->session->remove("{$this->namespace}.{$key}");
    }

    public function flash(string $key, mixed $value): void
    {
        $this->session->flash("{$this->namespace}.{$key}", $value);
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->session->getFlash("{$this->namespace}.{$key}", $default);
    }
}