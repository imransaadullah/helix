<?php

namespace Helix\Modules;

use Helix\Core\Container\HelixContainer;

interface ModuleInterface
{
    public function getName(): string;
    public function register(HelixContainer $container): void;
    public function boot(): void;
}
