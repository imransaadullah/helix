<?php
namespace Helix\Core\Contracts;

interface BootstrapperInterface {
    public function addPhase(callable $phase, string $name, int $priority = 50, bool $critical = true): self;
    public function boot(): void;
    public function getPhases(): array;
}
