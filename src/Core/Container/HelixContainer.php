<?php
namespace Helix\Core\Container;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;

class ServiceNotFoundException extends \Exception implements NotFoundExceptionInterface {}
class ContainerException extends \Exception implements ContainerExceptionInterface {}

final class HelixContainer implements ContainerInterface
{
    private array $bindings = [];
    private array $contextual = [];
    private array $resolvingStack = [];
    private array $aliases = [];
    private array $factories = [];
    private array $singletons = [];
    private ?ContainerInterface $delegate = null;
    private array $reflectionCache = [];

    public function add(string $id, mixed $concrete, bool $shared = false): void {
        $this->bindings[$id] = [
            'concrete' => $this->wrapConcrete($concrete),
            'shared' => $shared,
            'instance' => null
        ];
    }

    public function singleton(string $id, mixed $concrete): void {
        $this->singletons[$id] = [
            'concrete' => $this->wrapConcrete($concrete),
            'instance' => null
        ];
    }

    public function factory(string $id, callable $factory): void {
        $this->factories[$id] = $this->wrapConcrete($factory);
    }

    public function share(string $id, mixed $concrete): void {
        $this->add($id, $concrete, true);
    }

    public function alias(string $alias, string $target): void {
        if ($alias === $target) {
            throw new ContainerException("Cannot alias a service to itself");
        }
        $this->aliases[$alias] = $target;
    }

    public function delegate(ContainerInterface|callable $delegate): void {
        $this->delegate = $delegate instanceof ContainerInterface 
            ? $delegate 
            : $this->createDelegateContainer($delegate);
    }

    public function has(string $id): bool {
        $id = $this->resolveAlias($id);
        
        return isset($this->bindings[$id]) 
            || isset($this->factories[$id])
            || isset($this->singletons[$id])
            || $this->delegate?->has($id) 
            || interface_exists($id) 
            || class_exists($id);
    }

    public function get(string $id): mixed {
        $id = $this->resolveAlias($id);

        // Check singletons first
        if (isset($this->singletons[$id])) {
            $singleton = &$this->singletons[$id];
            if ($singleton['instance'] === null) {
                $singleton['instance'] = $singleton['concrete']($this);
            }
            return $singleton['instance'];
        }

        if (isset($this->factories[$id])) {
            return $this->factories[$id]($this);
        }

        if (!isset($this->bindings[$id])) {
            return $this->resolveExternalService($id);
        }

        $binding = &$this->bindings[$id];

        if ($binding['shared'] && $binding['instance'] !== null) {
            return $binding['instance'];
        }

        $instance = $binding['concrete']($this);

        if ($binding['shared']) {
            $binding['instance'] = $instance;
        }

        return $instance;
    }

    public function call(callable|array $callable, array $args = []): mixed {
        $reflection = is_array($callable)
            ? $this->getReflectionMethod($callable[0], $callable[1])
            : $this->getReflectionFunction($callable);

        $parameters = [];
        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();
            $parameters[$name] = array_key_exists($name, $args)
                ? $args[$name]
                : $this->resolveParameter($param, is_array($callable) 
                    ? get_class($callable[0]) 
                    : 'Closure');
        }

        return $reflection->invokeArgs(
            is_array($callable) ? $callable[0] : null,
            array_values($parameters)
        );
    }

    public function when(string $requestingClass): ContextualBindingBuilder {
        return new ContextualBindingBuilder($this, $requestingClass);
    }

    public function addContextualBinding(string $requestingClass, string $dependency, Closure $concrete): void {
        $this->contextual[$requestingClass][$dependency] = $this->wrapConcrete($concrete);
    }

    public function set(string $id, mixed $value): void {
        $this->bindings[$id] = [
            'concrete' => fn() => $value,
            'shared' => false,
            'instance' => null
        ];
    }

    public function getDelegate(): ?ContainerInterface {
        return $this->delegate;
    }

    public function setDelegate(?ContainerInterface $delegate): void {
        $this->delegate = $delegate;
    }

    public function clear(): void {
        $this->bindings = [];
        $this->contextual = [];
        $this->resolvingStack = [];
        $this->aliases = [];
        $this->factories = [];
        $this->singletons = [];
        $this->delegate = null;
        $this->reflectionCache = [];
    }

    // Magic methods
    public function __get(string $id): mixed { return $this->get($id); }
    public function __set(string $id, mixed $value): void { $this->set($id, $value); }
    public function __isset(string $id): bool { return $this->has($id); }
    public function __unset(string $id): void { 
        unset($this->bindings[$id], $this->factories[$id], $this->singletons[$id]); 
    }
    public function __invoke(string $id): mixed { return $this->get($id); }

    // Serialization
    public function __serialize(): array {
        return [
            'bindings' => array_filter($this->bindings, fn($b) => !$b['concrete'] instanceof Closure),
            'contextual' => array_filter($this->contextual, fn($c) => !$c instanceof Closure),
            'aliases' => $this->aliases,
            'singletons' => array_filter($this->singletons, fn($s) => !$s['concrete'] instanceof Closure),
            'delegate' => $this->delegate ? get_class($this->delegate) : null
        ];
    }

    public function __unserialize(array $data): void {
        $this->bindings = $data['bindings'] ?? [];
        $this->contextual = $data['contextual'] ?? [];
        $this->aliases = $data['aliases'] ?? [];
        $this->singletons = $data['singletons'] ?? [];
        $this->delegate = isset($data['delegate']) ? new $data['delegate']() : null;
    }

    // Internal implementation
    private function resolveAlias(string $id): string {
        $visited = [];
        while (isset($this->aliases[$id])) {
            if (isset($visited[$id])) {
                throw new ContainerException("Circular alias detected: " . implode(' -> ', array_keys($visited)) . " -> $id");
            }
            $visited[$id] = true;
            $id = $this->aliases[$id];
        }
        return $id;
    }

    private function resolveExternalService(string $id): mixed {
        if ($this->delegate?->has($id)) {
            return $this->delegate->get($id);
        }
        
        if (interface_exists($id) || class_exists($id)) {
            $this->add($id, $id); // auto-wireable
            return $this->get($id);
        }
        
        throw new ServiceNotFoundException("Service {$id} not found");
    }

    private function autowire(string $class): object {
        if (in_array($class, $this->resolvingStack, true)) {
            throw new ContainerException("Circular dependency detected: " . 
                implode(" > ", $this->resolvingStack) . " > $class");
        }

        $this->resolvingStack[] = $class;
        
        try {
            $refClass = $this->getReflectionClass($class);
            
            if (!$refClass->isInstantiable()) {
                throw new ContainerException("Class {$class} is not instantiable");
            }

            $constructor = $refClass->getConstructor();
            if (!$constructor) {
                return new $class();
            }

            $args = array_map(
                fn(ReflectionParameter $param) => $this->resolveParameter($param, $class),
                $constructor->getParameters()
            );

            return $refClass->newInstanceArgs($args);
        } finally {
            array_pop($this->resolvingStack);
        }
    }

    private function resolveParameter(ReflectionParameter $param, string $context): mixed {
        $paramType = $param->getType();
        $paramName = $param->getName();

        if (!$paramType || $paramType->isBuiltin()) {
            if ($param->isDefaultValueAvailable()) {
                return $param->getDefaultValue();
            }
            throw new ContainerException(
                "Cannot resolve parameter \${$paramName} for {$context}"
            );
        }

        $dependency = $paramType->getName();

        return isset($this->contextual[$context][$dependency])
            ? $this->contextual[$context][$dependency]($this)
            : $this->get($dependency);
    }

    public function wrapConcrete(mixed $concrete): Closure {
        if ($concrete instanceof Closure) {
            return $concrete->bindTo($this);
        }

        if (is_string($concrete) && (class_exists($concrete) || interface_exists($concrete))) {
            return fn() => $this->autowire($concrete);
        }

        return fn() => $concrete;
    }

    private function createDelegateContainer(callable $resolver): ContainerInterface {
        return new class($resolver, $this) implements ContainerInterface {
            public function __construct(
                private $resolver,
                private ContainerInterface $parent
            ) {}
            
            public function get(string $id): mixed { 
                try {
                    $result = ($this->resolver)($id);
                    if ($result === null && $this->parent->has($id)) {
                        return $this->parent->get($id);
                    }
                    return $result;
                } catch (\Throwable $e) {
                    if ($this->parent->has($id)) {
                        return $this->parent->get($id);
                    }
                    throw new ServiceNotFoundException("Service {$id} not found in delegate", 0, $e);
                }
            }
            
            public function has(string $id): bool { 
                try {
                    return ($this->resolver)($id) !== null || $this->parent->has($id);
                } catch (\Throwable $e) {
                    return $this->parent->has($id);
                }
            }
        };
    }

    // Reflection caching
    private function getReflectionClass(string $class): ReflectionClass {
        return $this->reflectionCache[$class] ??= new ReflectionClass($class);
    }

    private function getReflectionMethod($class, string $method): ReflectionMethod {
        $key = is_object($class) ? get_class($class) : $class;
        $key .= '::' . $method;
        
        return $this->reflectionCache[$key] ??= new ReflectionMethod($class, $method);
    }

    private function getReflectionFunction(callable $function): ReflectionFunction {
        $key = is_string($function) ? $function : spl_object_hash($function);
        
        return $this->reflectionCache[$key] ??= new ReflectionFunction($function);
    }
}
