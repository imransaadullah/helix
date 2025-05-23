<?php

namespace Helix\Database\Contracts;

interface StatementInterface {
    public function execute(): bool;
    public function fetch(): mixed;
    public function fetchAll(): array;
    public function rowCount(): int;
}
