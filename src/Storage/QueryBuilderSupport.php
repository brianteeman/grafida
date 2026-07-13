<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Storage;

/**
 * Small type-narrowing helpers shared by every `DatabaseInterface`-based
 * repository.
 *
 * `Joomla\Database\DatabaseInterface`/`QueryInterface` leave several methods
 * either untyped (`insertid()`, `loadResult()`, `loadAssoc()`, …) or typed
 * `array|string` (`quoteName()`) because the same method serves both a
 * single-identifier and a multi-identifier call shape. Every repository here
 * only ever uses the single-identifier / single-value shape, so these helpers
 * narrow the result with a real runtime check rather than an unchecked cast.
 *
 * Requires the using class to declare `private readonly DatabaseInterface $db`.
 */
trait QueryBuilderSupport
{
    /** Quotes a single identifier (column/table name). */
    private function qn(string $name): string
    {
        $quoted = $this->db->quoteName($name);

        if (!is_string($quoted)) {
            throw new \LogicException('quoteName() unexpectedly returned an array for a single identifier.');
        }

        return $quoted;
    }

    /** Returns the id of the last INSERT as an int. */
    private function lastInsertId(): int
    {
        $id = $this->db->insertid();

        if (is_int($id)) {
            return $id;
        }

        if (is_string($id) && is_numeric($id)) {
            return (int) $id;
        }

        throw new \LogicException('insertid() returned an unexpected value.');
    }

    /** Narrows a scalar (or null) database value to a string (or null). */
    private function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new \LogicException('Expected a scalar database value.');
    }
}
