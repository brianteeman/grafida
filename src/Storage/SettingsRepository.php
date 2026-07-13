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
use Joomla\Database\ParameterType;

/**
 * Key/value application settings (e.g. the UI language override).
 */
final class SettingsRepository
{
    use QueryBuilderSupport;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    public function get(string $key, ?string $default = null): ?string
    {
        $query = $this->db->createQuery()
            ->select($this->qn('value'))
            ->from($this->qn('settings'))
            ->where($this->qn('key') . ' = :key')
            ->bind(':key', $key, ParameterType::STRING);

        $value = $this->db->setQuery($query)->loadResult();

        return $this->toStringOrNull($value) ?? $default;
    }

    public function set(string $key, string $value): void
    {
        // UPSERT: no builder vocabulary for ON CONFLICT. excluded.value means each
        // placeholder is bound exactly once (a re-used named placeholder blows up
        // with "column index out of range" under ATTR_EMULATE_PREPARES => false).
        $query = $this->db->createQuery()
            ->setQuery(
                'INSERT INTO settings (key, value) VALUES (:k, :v) '
                . 'ON CONFLICT(key) DO UPDATE SET value = excluded.value'
            )
            ->bind(':k', $key, ParameterType::STRING)
            ->bind(':v', $value, ParameterType::STRING);

        $this->db->setQuery($query)->execute();
    }
}
