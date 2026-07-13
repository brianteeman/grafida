<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Support;

use Grafida\Storage\DatabaseFactory;
use Grafida\Storage\Migrator;
use Joomla\Database\DatabaseInterface;
use PDO;

/** Builds a migrated, in-memory SQLite database for tests that want a bare repository. */
final class TestDatabase
{
    public static function memory(): DatabaseInterface
    {
        $db = (new DatabaseFactory())->create(':memory:');
        (new Migrator($db))->migrate();

        return $db;
    }

    /** The underlying PDO connection, for tests that seed/inspect rows directly. */
    public static function connection(DatabaseInterface $db): PDO
    {
        $connection = $db->getConnection();
        \assert($connection instanceof PDO);

        return $connection;
    }
}
