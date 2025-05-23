<?php

namespace Helix\Database;

use Helix\Database\Contracts\ConnectionInterface;
use Helix\Database\Contracts\StatementInterface;
use PDO;

class Connection implements ConnectionInterface {
    private PDO $pdo;

    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = []
    ) {
        $this->pdo = new PDO($dsn, $username, $password, array_replace([
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ], $options));
    }

    public function query(string $sql, array $params = []): StatementInterface {
        $stmt = $this->pdo->prepare($sql);
        return new Statement($stmt, $params);
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    public function beginTransaction(): bool {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool {
        return $this->pdo->commit();
    }

    public function rollback(): bool {
        return $this->pdo->rollBack();
    }

    public function transaction(callable $callback): mixed {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\PDOException $e) {
            $this->rollBack();
            throw $e;
        }
    }
}
