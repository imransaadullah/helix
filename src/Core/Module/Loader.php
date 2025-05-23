<?php

use Helix\Core\Exceptions\MissingDependencyException;
use Psr\Container\ContainerInterface;

final class Loader
{
    public function __construct(private ContainerInterface $container) {}
    public function load(string $path): void {
        $manifest = json_decode(file_get_contents("$path/module.json"), true);

        $this->validateDependencies($manifest['requires']);

        $module = new $manifest['entrypoint']();
        $module->register($this->container);
    }

    private function validateDependencies(array $deps): void {
        foreach ($deps as $module) {
            if (!$this->container->has("module.$module")) {
                throw new MissingDependencyException($module);
            }
        }
    }
}
