<?php

namespace BlueprintAU\Radiant\Drivers\MySql;

use BlueprintAU\Radiant\Connections\MySql\MySqlGrammar;
use BlueprintAU\Radiant\Interfaces\ConnectionInterface;
use BlueprintAU\Radiant\Interfaces\GrammarInterface;
use BlueprintAU\Radiant\Query\Builder;
use PDO;

class MySqlDriver implements ConnectionInterface
{

    public private(set) PDO $pdo;
    public private(set) GrammarInterface $grammar;

    public function __construct(array $config)
    {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']}";
        $this->pdo = new PDO($dsn, $config["username"], $config["password"]);
        $this->grammar = new MySqlGrammar();
    }

    public function select(Builder $query): array
    {
        $sql = $this->grammar->compileSelect($query);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($query->getBindings());

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(Builder $query, array $values): int|string|bool
    {
        $sql = $this->grammar->compileInsert($query, $values);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($values));

        return $this->pdo->lastInsertId();
    }

    public function update(Builder $query, array $values): int
    {
        $sql = $this->grammar->compileUpdate($query, $values);
        $stmt = $this->pdo->prepare($sql);
        // Combine update payload values with existing WHERE bindings
        $stmt->execute(array_merge(array_values($values), $query->getBindings()));

        return $stmt->rowCount();
    }

    public function delete(Builder $query): int
    {
        $sql = $this->grammar->compileDelete($query);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($query->getBindings());

        return $stmt->rowCount();
    }

    public function getGrammar(): GrammarInterface
    {
        return $this->grammar;
    }
}