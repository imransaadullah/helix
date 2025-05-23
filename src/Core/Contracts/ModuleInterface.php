<?php
namespace Helix\Core\Contracts;

use Psr\Container\ContainerInterface;

interface ModuleInterface {
    public function getName(): string;
    public function init(ContainerInterface $container): void;
}
