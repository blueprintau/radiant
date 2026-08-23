<?php

namespace BlueprintAU\Radiant\Connections\MySql;

use BlueprintAU\Radiant\Interfaces\GrammarInterface;
use BlueprintAU\Radiant\Query\Builder;

class MySqlGrammar implements GrammarInterface
{

    public function compileSelect(Builder $query): string
    {
        // TODO: Implement compileSelect() method.
    }

    public function compileInsert(Builder $query, array $values): string
    {
        // TODO: Implement compileInsert() method.
    }

    public function compileUpdate(Builder $query, array $values): string
    {
        // TODO: Implement compileUpdate() method.
    }

    public function compileDelete(Builder $query): string
    {
        // TODO: Implement compileDelete() method.
    }

    public function wrap(string $value): string
    {
        // TODO: Implement wrap() method.
    }
}