<?php

namespace Helix\Routing;

use Helix\Http\Request;
use Helix\Http\Response;
use Helix\Core\Container\HelixContainer;

class Pipeline
{
    private array $middlewares;
    private \Closure $destination;
    
    public function __construct(
        private HelixContainer $container,
        array $middlewares = []
    ) {
        $this->middlewares = $middlewares;
    }
    
    public function then(callable $destination): self
    {
        $this->destination = $destination;
        return $this;
    }
    
    public function process(Request $request): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            $this->carry(),
            $this->destination
        );
        
        return $pipeline($request);
    }
    
    private function carry(): \Closure
    {
        return function ($stack, $middleware) {
            return function ($request) use ($stack, $middleware) {
                if (is_string($middleware)) {
                    $middleware = $this->container->get($middleware);
                }
                
                if ($middleware instanceof \Closure) {
                    return $middleware($request, $stack);
                }
                
                return $middleware->handle($request, $stack);
            };
        };
    }
}