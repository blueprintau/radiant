<?php

namespace BlueprintAU\Radiant\Query;

class Builder
{
    public string $from = '';
    public array $columns = ['*'];
    public array $wheres = [];
    public ?int $limit = null;

    // Ordered binding buckets matching SQL structure: SELECT -> WHERE -> HAVING
    protected array $bindings = [
        'select' => [],
        'where'  => [],
    ];

    public function __construct(string $from) {
        $this->from = $from;
    }

    public function select(string ...$columns): static
    {
        $this->columns = $columns;
        return $this;
    }

    public function where(string $column, string $operator, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = compact('column', 'operator', 'value');
        $this->bindings['where'][] = $value;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    public function getBindings(): array
    {
        return array_merge(
            $this->bindings['select'],
            $this->bindings['where']
        );
    }

    // Terminal execution methods
    public function get(): array
    {
        return $this->connection->select($this);
    }

    public function insert(array $values): int|string|bool
    {
        return $this->connection->insert($this, $values);
    }
}