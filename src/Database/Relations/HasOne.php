<?php

namespace Helix\Database\Relations;

use Helix\Database\Model;

class HasOne implements Relation
{
    public function __construct(
        protected Model $parent,
        protected string $related,
        protected string $foreignKey,
        protected string $localKey = 'id'
    ) {}

    public function getResults(): ?Model
    {
        return $this->related::query()
            ->where($this->foreignKey, '=', $this->parent->{$this->localKey})
            ->first();
    }
}