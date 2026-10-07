<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Radiant\Database\Schema\Inspectors\MariaDbSchemaInspector;
use BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector;
use Override;

/**
 * A database connection backed by MariaDB.
 *
 * MariaDB speaks the MySQL wire protocol and SQL surface, so the
 * grammar, savepoint and `GET_LOCK` behavior are inherited unchanged —
 * only the live-schema reader is swapped, for the JSON-as-longtext and
 * current-timestamp spellings MariaDB diverges on.
 *
 * @see \BlueprintAU\Radiant\Database\Connections\MySqlConnection
 */
final class MariaDbConnection extends MySqlConnection
{
    /**
     * The dialect's live-schema reader.
     *
     * @return MariaDbSchemaInspector
     */
    #[Override]
    protected function getDefaultSchemaInspector(): SchemaInspector
    {
        return new MariaDbSchemaInspector($this->pdo);
    }
}
