<?php

namespace Helix\Core\Contracts;

interface ConfigInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function all(): array;

    public function validateRequiredKeys(array $requiredKeys): void;
}
