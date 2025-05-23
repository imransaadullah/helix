<?php

namespace Helix\Core\Bootstrap;

use Helix\Core\Conf\ConfigLoader;
use Helix\Core\Container\HelixContainer;
use Helix\Core\Contracts\BootstrapperInterface;
use Psr\Container\ContainerInterface;
use Helix\Core\Exceptions\BootException;
use Helix\Core\Log\FileLogger;
use Helix\Core\Log\LoggerInterface;
use Helix\Database\ConnectionManager;
use Helix\Database\Contracts\ConnectionInterface;
use Helix\Database\Model;
use Helix\Events\Dispatcher;

final class HelixBootstrapper implements BootstrapperInterface
{
    private array $phases = [];

    public function __construct(
        private HelixContainer $container
    ) {}

    public function addPhase(callable $phase, string $name, int $priority = 50, bool $critical = true): self
    {
        $this->phases[$name] = [
            'callable' => $phase,
            'priority' => $priority,
            'critical' => $critical,
            'dependencies' => $this->resolveDependencies($phase)
        ];

        return $this;
    }

    public function addCorePhases(): void
    {
        // 1. Configuration Loading
        $this->addPhase(
            fn(ConfigLoader $config) => $config->load('.env'),
            'config_init',
            priority: 5
        );

        // 2. Logger Setup
        // $this->addPhase(
        //     fn(HelixContainer $container) =>
        //     $container->add(LoggerInterface::class, new FileLogger()),
        //     'logger_init',
        //     priority: 10
        // );

        // 3. Database Connections (Multi-connection version)
        // $this->addPhase(
        //     function (HelixContainer $container, ConfigLoader $config) {
        //         $manager = new ConnectionManager();

        //         // Primary connection
        //         $manager->addConnection(
        //             'primary',
        //             $config->get('DB_PRIMARY_DSN'),
        //             // ... other params
        //             true
        //         );

        //         // Analytics connection
        //         $manager->addConnection(
        //             'analytics',
        //             $config->get('DB_ANALYTICS_DSN'),
        //             // ... other params
        //             false
        //         );

        //         $container->add(ConnectionManager::class, $manager);
        //         $container->add(ConnectionInterface::class, fn() => $manager->getConnection());
        //         $container->add('analytics_connection', fn() => $manager->getConnection('analytics'));

        //         Model::setConnection($manager->getConnection());
        //     },
        //     'db_init',
        //     priority: 20
        // );
    }

    public function registerCoreServices(): void
    {
        $this->container->add(
            LoggerInterface::class,
            fn() => new FileLogger('logs/app.log'),
            shared: true
        );

        $this->container->add(
            Dispatcher::class,
            fn() => new Dispatcher(),
            shared: true
        );
    }

    public function boot(): void
    {
        uasort($this->phases, fn($a, $b) => $a['priority'] <=> $b['priority']);

        foreach ($this->phases as $name => $phase) {
            try {
                $this->executePhase($name, $phase);
            } catch (\Throwable $e) {
                if ($phase['critical']) {
                    throw new BootException("Critical phase failed: {$name}", 0, $e);
                }
                // Log non-critical failures
            }
        }
    }

    private function executePhase(string $name, array $phase): mixed
    {
        $args = array_map(
            fn(string $dependency) => $this->container->get($dependency),
            $phase['dependencies']
        );

        return call_user_func_array($phase['callable'], $args);
    }

    private function resolveDependencies(callable $phase): array
    {
        $reflection = is_array($phase)
            ? new \ReflectionMethod($phase[0], $phase[1])
            : new \ReflectionFunction($phase);

        return array_map(
            fn($param) => $param->getType()->getName(),
            $reflection->getParameters()
        );
    }

    public function getPhases(): array
    {
        return $this->phases;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }
}
