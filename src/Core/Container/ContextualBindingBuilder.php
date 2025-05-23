<?php
namespace Helix\Core\Container;

use Closure;

final class ContextualBindingBuilder
{
    private string $dependency;

    public function __construct(
        private HelixContainer $container,
        private string $requestingClass
    ) {}

    public function needs(string $dependency): self {
        $this->dependency = $dependency;
        return $this;
    }

    public function give(Closure $concrete): void {
        $this->container->addContextualBinding(
            $this->requestingClass,
            $this->dependency,
            $this->container->wrapConcrete($concrete)
        );
    }
}
