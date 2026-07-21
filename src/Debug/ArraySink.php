<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Debug;

/**
 * Unconditional in-memory collector of every record handed to it, in
 * insertion order.
 *
 * Used by Diagnose Connection, which must capture the probe's exchanges
 * regardless of whether the Request Log setting is on.
 */
final class ArraySink implements RecordSink
{
    /** @var list<RequestRecord> */
    private array $records = [];

    public function record(RequestRecord $record): void
    {
        $this->records[] = $record;
    }

    /** @return list<RequestRecord> Insertion order. */
    public function entries(): array
    {
        return $this->records;
    }
}
