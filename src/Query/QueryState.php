<?php

namespace BlueprintAU\Radiant\Query;

class QueryState
{
    public string $source = ''; // Table name, CSV path, API endpoint
    public array $columns = ['*'];
    public array $wheres = [];
    public array $orders = [];
    public ?int $limit = null;
    public ?int $offset = null;
}