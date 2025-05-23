<?php

namespace Helix\Database;

use Helix\Database\Contracts\StatementInterface;
use PDOStatement;

class Statement implements StatementInterface {
    private PDOStatement $stmt;
    private array $params;

    public function __construct(PDOStatement $stmt,  array $params = [] ) {
        $this->stmt = $stmt;
        $this->params = $params;
    }

    public function execute(): bool {
        return $this->stmt->execute($this->params);
    }

    public function fetch(): mixed {
        $this->execute();
        return $this->stmt->fetch();
    }

    public function fetchAll(): array {
        $this->execute();
        return $this->stmt->fetchAll();
    }

    public function rowCount(): int {
        return $this->stmt->rowCount();
    }
}
