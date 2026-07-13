<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Storage;

use Joomla\Database\Sqlite\SqliteDriver;
use PDO;

/**
 * SQLite driver that applies the application's connection pragmas as soon as
 * the underlying PDO connection is established.
 */
final class SqliteDatabase extends SqliteDriver
{
    /**
     * Connects to the database, then applies the WAL / foreign-key /
     * busy-timeout pragmas the application relies on.
     *
     * @return void
     */
    public function connect()
    {
        if ($this->connection !== null) {
            return;
        }

        parent::connect();

        /** @var PDO $connection */
        $connection = $this->connection;

        $connection->exec('PRAGMA journal_mode = WAL');
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA busy_timeout = 5000');
    }
}
