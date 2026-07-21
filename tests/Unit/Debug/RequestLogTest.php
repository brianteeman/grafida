<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Debug;

use Grafida\Debug\RequestLog;
use Grafida\Debug\RequestLogService;
use Grafida\Debug\RequestRecord;
use Grafida\Storage\SettingsRepository;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\TestCase;

final class RequestLogTest extends TestCase
{
    private function service(): RequestLogService
    {
        return new RequestLogService(new SettingsRepository(TestDatabase::memory()));
    }

    private function record(string $url): RequestRecord
    {
        return new RequestRecord(
            timestamp: gmdate('Y-m-d H:i:s'),
            durationMs: 1.23,
            method: 'GET',
            url: $url,
            requestHeaders: [],
            requestBody: null,
            status: 200,
            responseHeaders: [],
            responseBody: '{}',
        );
    }

    public function testRecordsNothingWhileDisabled(): void
    {
        $log = new RequestLog($this->service());

        $log->record($this->record('https://example.test/1'));

        self::assertSame([], $log->entries());
    }

    public function testEntriesAreReturnedNewestFirst(): void
    {
        $service = $this->service();
        $service->set(true);
        $log = new RequestLog($service);

        $log->record($this->record('https://example.test/1'));
        $log->record($this->record('https://example.test/2'));
        $log->record($this->record('https://example.test/3'));

        $entries = $log->entries();

        self::assertCount(3, $entries);
        self::assertSame('https://example.test/3', $entries[0]->url);
        self::assertSame('https://example.test/2', $entries[1]->url);
        self::assertSame('https://example.test/1', $entries[2]->url);
    }

    public function testMoreThanCapacityKeepsOnlyTheNewestTwenty(): void
    {
        $service = $this->service();
        $service->set(true);
        $log = new RequestLog($service);

        for ($i = 1; $i <= RequestLog::CAPACITY + 5; $i++) {
            $log->record($this->record('https://example.test/' . $i));
        }

        $entries = $log->entries();

        self::assertCount(RequestLog::CAPACITY, $entries);
        // Newest first: the last recorded is index 0, the oldest surviving one
        // is the one recorded 20 entries back.
        self::assertSame('https://example.test/' . (RequestLog::CAPACITY + 5), $entries[0]->url);
        self::assertSame('https://example.test/6', $entries[\count($entries) - 1]->url);
    }

    public function testClearEmptiesTheLog(): void
    {
        $service = $this->service();
        $service->set(true);
        $log = new RequestLog($service);

        $log->record($this->record('https://example.test/1'));
        $log->record($this->record('https://example.test/2'));
        self::assertNotEmpty($log->entries());

        $log->clear();

        self::assertSame([], $log->entries());
    }
}
