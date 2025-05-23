<?php

namespace Helix\Database\Contracts;

interface ConnectionInterface {
    public function query(string $sql, array $params = []): StatementInterface;
    public function getPdo(): \PDO;
    public function beginTransaction(): bool;
    public function commit(): bool;
    public function rollBack(): bool;
    public function transaction(callable $callback): mixed;
}
