<?php

namespace Helix\Database;

use DateTimeInterface;
use Exception;
use Helix\Database\Relations\BelongsTo;
use Helix\Database\Relations\HasMany;
use Helix\Database\Relations\HasOne;
use Helix\Database\Relations\Relation;
use InvalidArgumentException;

abstract class Model
{
    protected static ?Connection $connection = null;
    protected static string $table;
    protected static string $primaryKey = 'id';
    protected static bool $timestamps = true;
    protected static bool $softDeletes = false;
    protected static string $deletedAtColumn = 'deleted_at';

    protected array $attributes = [];
    protected array $original = [];
    protected bool $exists = false;

    // Relationship cache
    protected array $relations = [];

    public function __construct(array $attributes = [], bool $exists = false)
    {
        $this->original = $attributes;
        $this->attributes = $attributes;
        $this->exists = $exists;

        if ($exists && static::$timestamps && !isset($this->attributes['created_at'])) {
            $this->setCreatedAt($this->freshTimestamp());
        }
    }

    public function __get($key)
    {
        if (method_exists($this, $key)) {
            return $this->getRelationship($key);
        }

        return $this->attributes[$key] ?? null;
    }

    public function __set($key, $value)
    {
        if (method_exists($this, $key)) {
            $this->setRelation($key, $value);
        } else {
            $this->attributes[$key] = $value;
        }
    }

    public function __isset($key)
    {
        return isset($this->attributes[$key]) || method_exists($this, $key);
    }

    public static function setConnection(Connection $conn): void
    {
        static::$connection = $conn;
    }

    protected static function resolveTable(): string
    {
        return static::$table ?? strtolower((new \ReflectionClass(static::class))->getShortName()) . 's';
    }

    public static function query(): QueryBuilder
    {
        $builder = (new QueryBuilder(static::$connection))->table(static::resolveTable())
            ->mapTo(static::class);

        if (static::$softDeletes) {
            $builder->whereNull(static::$deletedAtColumn);
        }

        return $builder;
    }

    public static function withTrashed(): QueryBuilder
    {
        return (new QueryBuilder(static::$connection))
            ->table(static::resolveTable())
            ->mapTo(static::class);
    }

    public static function onlyTrashed(): QueryBuilder
    {
        if (!static::$softDeletes) {
            throw new Exception('Soft deletes not enabled for ' . static::class);
        }

        return static::withTrashed()
            ->whereNotNull(static::$deletedAtColumn);
    }

    public static function all(): array
    {
        return static::query()->get();
    }

    public static function find($id): ?static
    {
        return static::query()
            ->where(static::$primaryKey, '=', $id)
            ->first();
    }

    public static function findOrFail($id): static
    {
        if (is_null($model = static::find($id))) {
            throw new Exception("No record found for ID {$id}");
        }

        return $model;
    }

    public static function where(string|\Closure $column, ?string $operator = null, $value = null): QueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    public static function create(array $data): static
    {
        $model = new static($data);
        $model->save();
        return $model;
    }

    public function save(): bool
    {
        if ($this->exists) {
            return $this->performUpdate();
        }

        return $this->performInsert();
    }


    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if (static::$softDeletes) {
            return $this->performSoftDelete();
        }

        return $this->performDelete();
    }

    protected function performInsert(): bool
    {
        if (static::$timestamps) {
            $this->setCreatedAt($this->freshTimestamp());
            $this->setUpdatedAt($this->freshTimestamp());
        }

        $attributes = $this->getAttributesForSave();

        if (empty($attributes)) {
            return false;
        }

        $id = static::query()->insertGetId($attributes);

        if ($id) {
            $this->attributes[static::$primaryKey] = $id;
            $this->exists = true;
            $this->original = $this->attributes;
            return true;
        }

        return false;
    }

    protected function performUpdate(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if (static::$timestamps) {
            $this->setUpdatedAt($this->freshTimestamp());
        }

        $dirty = $this->getDirty();

        if (empty($dirty)) {
            return true;
        }

        $isSoftDeleting = static::$softDeletes;
        $isCurrentlyDeleted = $isSoftDeleting
            && (($this->original[static::$deletedAtColumn] ?? null) !== null);

        $query = $isCurrentlyDeleted
            ? static::withTrashed()
            : static::query();

        $query->where(static::$primaryKey, '=', $this->getKey());

        return $query->update($dirty);
    }

    protected function performDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $query = static::query()
            ->where(static::$primaryKey, '=', $this->getKey());

        if (static::$softDeletes) {
            $query->whereNull(static::$deletedAtColumn);
        }

        $deleted = $query->delete();

        if ($deleted) {
            $this->exists = false;
            return true;
        }

        return false;
    }

    protected function performSoftDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $timestamp = $this->freshTimestamp();

        if (static::$timestamps) {
            $this->setUpdatedAt($timestamp);
        }

        $attributes = [
            static::$deletedAtColumn => $timestamp,
        ];

        if (static::$timestamps) {
            $attributes['updated_at'] = $timestamp;
        }

        $deleted = static::withTrashed()
            ->where(static::$primaryKey, '=', $this->getKey())
            ->update($attributes);

        if ($deleted) {
            $this->attributes[static::$deletedAtColumn] = $timestamp;
            if (static::$timestamps) {
                $this->attributes['updated_at'] = $timestamp;
            }

            $this->original = $this->attributes;
            $this->exists = false;
            return true;
        }

        return false;
    }

    public function restore(): bool
    {
        if (!static::$softDeletes || !$this->getKey()) {
            return false;
        }

        $attributes = [static::$deletedAtColumn => null];

        if (static::$timestamps) {
            $timestamp = $this->freshTimestamp();
            $this->setUpdatedAt($timestamp);
            $attributes['updated_at'] = $timestamp;
        }

        $restored = static::withTrashed()
            ->where(static::$primaryKey, '=', $this->getKey())
            ->update($attributes);

        if ($restored) {
            $this->attributes[static::$deletedAtColumn] = null;
            $this->original = $this->attributes;
            $this->exists = true;
        }

        return (bool) $restored;
    }

    public static function updateById($id, array $data): bool
    {
        $model = static::findOrFail($id);
        $model->fill($data);
        return $model->save();
    }

    public static function deleteById($id): bool
    {
        $model = static::findOrFail($id);
        return $model->delete();
    }

    public function fill(array $data): static
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }

        return $this;
    }

    public function getKey()
    {
        return $this->attributes[static::$primaryKey] ?? null;
    }

    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $value !== $this->original[$key]) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function isDirty(): bool
    {
        return !empty($this->getDirty());
    }

    public function freshTimestamp(): string
    {
        return date('Y-m-d H:i:s');
    }

    public function setCreatedAt($value): void
    {
        $this->attributes['created_at'] = $value;
    }

    public function setUpdatedAt($value): void
    {
        $this->attributes['updated_at'] = $value;
    }

    protected function getAttributesForSave(): array
    {
        return $this->attributes;
    }

    protected function getRelationship(string $method)
    {
        if (isset($this->relations[$method])) {
            return $this->relations[$method];
        }

        $relation = $this->$method();

        if (!$relation instanceof Relation) {
            throw new Exception("Relationship method must return a Relation instance");
        }

        return $this->relations[$method] = $relation->getResults();
    }

    public function setRelation(string $relation, mixed $value): static
    {
        $this->relations[$relation] = $value;

        return $this;
    }

    public function getRelation(string $relation): mixed
    {
        return $this->relations[$relation] ?? null;
    }

    public function relationLoaded(string $relation): bool
    {
        return array_key_exists($relation, $this->relations);
    }

    public function unsetRelation(string $relation): void
    {
        unset($this->relations[$relation]);
    }

    /**
     * Define a one-to-one relationship.
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: static::$primaryKey;
        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a one-to-many relationship.
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: static::$primaryKey;
        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define an inverse one-to-one or many-to-one relationship.
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $ownerKey = $ownerKey ?: $related::getKeyName();
        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Get the primary key for the model.
     */
    public static function getKeyName(): string
    {
        return static::$primaryKey;
    }

    protected function getForeignKey(): string
    {
        return strtolower((new \ReflectionClass($this))->getShortName()) . '_id';
    }

    public static function hydrate(array $row): static
    {
        return new static($row, true);
    }

    public function toArray(): array
    {
        return $this->attributes;
    }
}
