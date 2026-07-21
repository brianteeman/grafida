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
 * In-memory ring buffer backing the Request Log screen.
 *
 * Container-shared, so it lives for the life of the process — the log is
 * deliberately not persisted to SQLite: it is cleared on app start and on
 * every site switch, so nothing about it is meant to outlive the process.
 *
 * Recording is a no-op while the {@see RequestLogService} setting is off —
 * the enabled check lives here, not in {@see RecordingTransport}, so there is
 * exactly one gate.
 */
final class RequestLog implements RecordSink
{
    /** The issue's stated capacity: store 20, display everything stored. */
    public const CAPACITY = 20;

    /** @var list<RequestRecord> */
    private array $records = [];

    public function __construct(private readonly RequestLogService $service) {}

    public function record(RequestRecord $record): void
    {
        if (!$this->service->current()) {
            return;
        }

        $this->records[] = $record;

        if (\count($this->records) > self::CAPACITY) {
            array_shift($this->records);
        }
    }

    /** @return list<RequestRecord> Newest first. */
    public function entries(): array
    {
        return array_reverse($this->records);
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
