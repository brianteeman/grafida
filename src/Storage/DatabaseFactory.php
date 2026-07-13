<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Storage;

use Joomla\Database\DatabaseInterface;
use PDO;

/**
 * Builds an unmigrated SQLite connection.
 *
 * Migration is deliberately not this class's job — the container's
 * `DatabaseInterface` factory owns that, so a caller that wants a bare,
 * unmigrated connection (e.g. to inspect a fresh file) can still have one.
 */
final class DatabaseFactory
{
    public function create(string $path): DatabaseInterface
    {
        return new SqliteDatabase([
            'driver'        => 'sqlite',
            'database'      => $path,
            'driverOptions' => [PDO::ATTR_EMULATE_PREPARES => false],
        ]);
    }
}
