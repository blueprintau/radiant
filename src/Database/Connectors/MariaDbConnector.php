<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connectors;

use BlueprintAU\Radiant\Database\Connections\MariaDbConnection;
use Pdo;
use Override;

/**
 * MariaDB connector — builds a PDO MySQL connection from config, as the
 * MySQL connector does.
 *
 * MariaDB speaks the MySQL wire protocol, so the DSN, charset allowlist
 * and forced PDO options are inherited unchanged — only the connection
 * class and the dialect label in the validation messages differ.
 *
 * @see \BlueprintAU\Radiant\Database\Connectors\MySqlConnector
 *
 * @phpstan-import-type PdoOptions from \BlueprintAU\Radiant\Database\Connectors\SqlConnector
 */
final class MariaDbConnector extends MySqlConnector
{
    /**
     * The dialect's name in the connector's validation messages.
     *
     * @return string
     */
    #[Override]
    protected function dialectLabel(): string
    {
        return 'MariaDB';
    }

    /**
     * The connection class this connector builds.
     *
     * @param  \PDO  $pdo
     * @return MariaDbConnection
     */
    #[Override]
    protected function makeConnection(Pdo $pdo): MariaDbConnection
    {
        return new MariaDbConnection($pdo);
    }
}
