<?php

namespace BlueprintAU\Radiant\Interfaces;

use BlueprintAU\Radiant\Query\Builder;

interface GrammarInterface
{
    public function compileSelect(Builder $query): string;
    public function compileInsert(Builder $query, array $values): string;
    public function compileUpdate(Builder $query, array $values): string;
    public function compileDelete(Builder $query): string;
    public function wrap(string $value): string;
}