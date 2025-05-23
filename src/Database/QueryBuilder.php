<?php

namespace Helix\Database;

use Helix\Database\Contracts\ConnectionInterface;

class QueryBuilder
{
    private ?string $table = null;
    private array $columns = ['*'];
    private array $wheres = [];
    private array $bindings = [];
    private array $joins = [];
    private array $groups = [];
    private array $havings = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $orders = [];
    private array $unions = [];
    private ?string $mapTo = null;
    private array $with = [];

    public function __construct(private ConnectionInterface $connection) {}

    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function select(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    public function with(array|string $relations): self
    {
        $relations = is_array($relations) ? $relations : func_get_args();
        $this->with = array_merge($this->with, $relations);
        return $this;
    }


    public function where(string|\Closure $column, ?string $operator = null, $value = null, string $boolean = 'AND', bool $not = false): self
    {
        if ($column instanceof \Closure) {
            $nested = new self($this->connection);
            $column($nested);
            $this->wheres[] = [$boolean, $not, $nested];
            $this->bindings = array_merge($this->bindings, $nested->bindings);
        } else {
            if ($not && !str_contains(strtoupper($operator), 'NOT')) {
                $operator = 'NOT ' . $operator;
            }
            $this->wheres[] = [$boolean, "{$column} {$operator} ?", false];
            $this->bindings[] = $value;
        }
        return $this;
    }

    public function orWhere(string|\Closure $column, ?string $operator = null, $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereNot(string|\Closure $column, ?string $operator = null, $value = null): self
    {
        return $this->where($column, $operator, $value, 'AND', true);
    }

    public function orWhereNot(string|\Closure $column, ?string $operator = null, $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR', true);
    }

    public function join(string $table, string $left, string $operator, string $right, string $type = 'INNER'): self
    {
        $this->joins[] = "{$type} JOIN {$table} ON {$left} {$operator} {$right}";
        return $this;
    }

    public function groupBy(array $columns): self
    {
        $this->groups = array_merge($this->groups, $columns);
        return $this;
    }

    public function having(string $column, string $operator, $value): self
    {
        $this->havings[] = "{$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orders[] = "{$column} {$direction}";
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function paginate(int $perPage, int $page = 1): array
    {
        return $this->limit($perPage)->offset(($page - 1) * $perPage)->get();
    }

    public function union(self $query): self
    {
        $this->unions[] = ['type' => 'UNION', 'query' => $query];
        return $this;
    }

    public function unionAll(self $query): self
    {
        $this->unions[] = ['type' => 'UNION ALL', 'query' => $query];
        return $this;
    }

    public function toSql(): string
    {
        $sql = "SELECT " . implode(', ', $this->columns) . " FROM {$this->table}";

        if (!empty($this->joins)) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->compileWheres($this->wheres);
        }

        if (!empty($this->groups)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if (!empty($this->havings)) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
        }

        if (!empty($this->orders)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        foreach ($this->unions as $union) {
            $sql .= " {$union['type']} (" . $union['query']->toSql() . ")";
            $this->bindings = array_merge($this->bindings, $union['query']->getBindings());
        }

        return $sql;
    }

    private function compileWheres(array $wheres): string
    {
        $sql = '';
        foreach ($wheres as [$boolean, $clause, $nested]) {
            if ($nested instanceof self) {
                $sql .= " {$boolean} (" . $nested->compileWheres($nested->wheres) . ")";
            } else {
                $sql .= " {$boolean} {$clause}";
            }
        }
        return ltrim($sql, 'ANDOR ');
    }

    public function getOne(): mixed
    {
        $this->limit(1);
        return $this->first();
    }

    public function get(): array
    {
        $sql = $this->toSql();
        $results = $this->connection->query($sql, $this->bindings)->fetchAll();

        if ($this->mapTo) {
            $models = array_map([$this->mapTo, 'hydrate'], $results);

            if (!empty($this->with)) {
                $this->eagerLoadRelations($models);
            }

            return $models;
        }

        return $results;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    // Basic insert
    public function insert(string $table, array $data): bool
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        return $this->connection->query($sql, array_values($data))->execute();
    }

    // Basic update
    public function delete(): bool
    {
        if ($this->table === null) {
            throw new \RuntimeException('No table specified for delete');
        }

        $sql = "DELETE FROM {$this->table}";

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->compileWheres($this->wheres);
        }

        return $this->connection->query($sql, $this->bindings)->execute();
    }

    public function update(array $data): bool
    {
        if ($this->table === null) {
            throw new \RuntimeException('No table specified for update');
        }

        $set = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($data)));
        $bindings = array_merge(array_values($data), $this->bindings);

        $sql = "UPDATE {$this->table} SET {$set}";

        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->compileWheres($this->wheres);
        }

        return $this->connection->query($sql, $bindings)->execute();
    }

    public function mapTo(string $class): self
    {
        $this->mapTo = $class;
        return $this;
    }

    public function first(): mixed
    {
        return $this->get()[0] ?? null;
    }

    private function quoteIdentifier(string $identifier): string
    {
        // Security: Prevent SQL injection in identifiers
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function eagerLoadRelations(array &$models): void
    {
        foreach ($this->with as $relationPath) {
            $segments = explode('.', $relationPath);
            $this->loadNestedRelations($models, $segments);
        }
    }

    protected function loadNestedRelations(array &$models, array $segments): void
    {
        $relation = array_shift($segments);
        $relatedModels = [];

        foreach ($models as $model) {
            if (!method_exists($model, $relation)) continue;

            $relationObj = $model->$relation();

            $results = $relationObj->getResults();
            $model->setRelation($relation, $results);

            if ($segments && $results) {
                $children = is_array($results) ? $results : [$results];
                $this->loadNestedRelations($children, $segments);
            }
        }
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $this->wheres[] = [$boolean, "{$column} IS NULL", false];
        return $this;
    }

    public function orWhereNull(string $column): self
    {
        return $this->whereNull($column, 'OR');
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $this->wheres[] = [$boolean, "{$column} IS NOT NULL", false];
        return $this;
    }

    public function orWhereNotNull(string $column): self
    {
        return $this->whereNotNull($column, 'OR');
    }

    public function insertGetId(array $data): ?int
    {
        if ($this->table === null) {
            throw new \RuntimeException('No table specified for insert');
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";

        $this->connection->query($sql, array_values($data))->execute();
        return (int) $this->connection->getPdo()->lastInsertId();
    }
}

// gtbankmailsupport@gtbank.com