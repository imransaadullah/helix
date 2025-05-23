<?php

namespace Helix\Database\Relations;

use Helix\Database\Model;

class BelongsTo implements Relation
{
    public function __construct(
        protected Model $parent,
        protected string $related,
        protected string $foreignKey,
        protected string $ownerKey = 'id'
    ) {}

    public function getResults(): ?Model
    {
        return $this->related::query()
            ->where($this->ownerKey, '=', $this->parent->{$this->foreignKey})
            ->first();
    }
}