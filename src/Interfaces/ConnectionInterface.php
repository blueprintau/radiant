<?php

namespace BlueprintAU\Radiant\Interfaces;

use BlueprintAU\Radiant\Query\Builder;

interface ConnectionInterface
{
    /**
     * Execute a read query and return raw records/rows.
     */
    public function select(Builder $query): array;

    /**
     * Insert records and return generated IDs or affected rows.
     */
    public function insert(Builder $query, array $values): int|string|bool;

    /**
     * Update records matching the query.
     */
    public function update(Builder $query, array $values): int;

    /**
     * Delete records matching the query.
     */
    public function delete(Builder $query): int;

    /**
     * Get the connection's query grammar instance.
     */
    public function getGrammar(): GrammarInterface;
}