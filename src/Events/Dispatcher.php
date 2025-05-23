<?php

namespace Helix\Events;

class Dispatcher
{
    private array $listeners = [];

    public function listen(string $event, callable $handler): void
    {
        $this->listeners[$event][] = $handler;
    }

    public function dispatch(object $event): void
    {
        foreach ($this->listeners[$event::class] ?? [] as $handler) {
            $handler($event);
        }
    }

    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]);
    }

    public function forget(string $event): void
    {
        unset($this->listeners[$event]);
    }

    public function forgetAll(): void
    {
        $this->listeners = [];
    }

    public function getListeners(string $event): array
    {
        return $this->listeners[$event] ?? [];
    }

    public function getAllListeners(): array
    {
        return $this->listeners;
    }

    public function flush(string $event): void
    {
        unset($this->listeners[$event]);
    }

    public function flushAll(): void
    {
        $this->listeners = [];
    }

    public function until(string $event, callable $handler): void
    {
        $this->listeners[$event][] = function ($eventInstance) use ($handler) {
            return $handler($eventInstance);
        };
    }
    public function dispatchSync(object $event): void
    {
        foreach ($this->listeners[$event::class] ?? [] as $handler) {
            $handler($event);
        }
    }

    public function dispatchAsync(object $event): void
    {
        foreach ($this->listeners[$event::class] ?? [] as $handler) {
            // Dispatch the event asynchronously (e.g., using a queue or a separate process)
            // This is a placeholder for actual async logic
            $handler($event);
        }
    }
    public function dispatchNow(object $event): void
    {
        foreach ($this->listeners[$event::class] ?? [] as $handler) {
            $handler($event);
        }
    }
}
