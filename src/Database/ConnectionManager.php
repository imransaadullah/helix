<?php

namespace Helix\Database;

use Helix\Database\Contracts\ConnectionInterface;

class ConnectionManager {
    private array $connections = [];
    private string $defaultConnection;

    public function addConnection(
        string $name,
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        bool $isDefault = false
    ): void {
        $this->connections[$name] = new Connection($dsn, $username, $password, $options);
        
        if ($isDefault) {
            $this->defaultConnection = $name;
        }
    }

    public function getConnection(?string $name = null): ConnectionInterface {
        $name ??= $this->defaultConnection;
        
        if (!isset($this->connections[$name])) {
            throw new \RuntimeException("Database connection {$name} not configured");
        }

        return $this->connections[$name];
    }
}
