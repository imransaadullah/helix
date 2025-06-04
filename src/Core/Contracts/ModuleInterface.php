<?php
namespace Helix\Core\Contracts;

use Helix\Core\Container\HelixContainer;

// use Psr\Container\ContainerInterface;

interface ModuleInterface {
    public function getName(): string;
    public function init(HelixContainer $container): void;
}
