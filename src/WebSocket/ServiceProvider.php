<?php
namespace Helix\WebSocket;

use Helix\Core\Contracts\ModuleInterface;
use Helix\Core\Container\HelixContainer;
use Helix\Core\Log\LoggerInterface;

class ServiceProvider implements ModuleInterface
{
    public function getName(): string
    {
        return 'websocket';
    }

    public function init(HelixContainer $container): void
    {
        $container->share(Server::class, function() use ($container) {
            $config = $container->get('config')->get('websocket', []);
            
            return new Server(
                $container->get(LoggerInterface::class),
                $config['host'] ?? '0.0.0.0',
                $config['port'] ?? 8080,
                $config['max_connections'] ?? 1000
            );
        });

        $container->add('WebSocketHandler', function() use ($container) {
            return new class($container) {
                public function __construct(private HelixContainer $container) {}
                
                public function handle(string $action, callable $handler): void
                {
                    $this->container->get(Server::class)->registerHandler($action, $handler);
                }
            };
        });
    }
}
