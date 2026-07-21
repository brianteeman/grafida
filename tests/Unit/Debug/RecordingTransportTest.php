<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Debug;

use Grafida\Debug\ArraySink;
use Grafida\Debug\RecordingTransport;
use Grafida\Debug\RecordSink;
use Grafida\Debug\RequestRecord;
use Grafida\Http\HttpException;
use Grafida\Http\HttpResponse;
use Grafida\Tests\Unit\Support\FakeTransport;
use Grafida\Tests\Unit\TestCase;

final class RecordingTransportTest extends TestCase
{
    public function testSuccessfulCallIsPassedThroughAndRecorded(): void
    {
        $inner = (new FakeTransport())->on(
            'https://example.test/v1/users/levels',
            new HttpResponse(200, '{"ok":true}', ['content-type' => 'application/vnd.api+json'])
        );
        $sink = new ArraySink();
        $rt   = new RecordingTransport($inner, $sink);

        $response = $rt->request('GET', 'https://example.test/v1/users/levels', ['Accept' => 'application/vnd.api+json']);

        // Passed through unchanged.
        self::assertSame(200, $response->status);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame(['content-type' => 'application/vnd.api+json'], $response->headers);

        $entries = $sink->entries();
        self::assertCount(1, $entries);

        $record = $entries[0];
        self::assertSame('GET', $record->method);
        self::assertSame('https://example.test/v1/users/levels', $record->url);
        self::assertSame(['Accept' => 'application/vnd.api+json'], $record->requestHeaders);
        self::assertSame(200, $record->status);
        self::assertSame(['content-type' => 'application/vnd.api+json'], $record->responseHeaders);
        self::assertSame('{"ok":true}', $record->responseBody);
        self::assertNull($record->error);
        self::assertGreaterThanOrEqual(0.0, $record->durationMs);
    }

    public function testTransportExceptionProducesARecordAndIsRethrown(): void
    {
        $inner = (new FakeTransport())->throwFor('https://example.test/boom');
        $sink  = new ArraySink();
        $rt    = new RecordingTransport($inner, $sink);

        try {
            $rt->request('GET', 'https://example.test/boom');
            self::fail('Expected HttpException was not thrown.');
        } catch (HttpException $e) {
            self::assertStringContainsString('boom', $e->getMessage());
        }

        $entries = $sink->entries();
        self::assertCount(1, $entries);

        $record = $entries[0];
        self::assertNull($record->status);
        self::assertNotNull($record->error);
        self::assertSame('https://example.test/boom', $record->url);
        self::assertGreaterThanOrEqual(0.0, $record->durationMs);
    }

    public function testASinkThatThrowsDoesNotBreakTheRequest(): void
    {
        $inner = (new FakeTransport())->on(
            'https://example.test/ok',
            new HttpResponse(200, 'fine')
        );

        $throwingSink = new class implements RecordSink {
            public function record(RequestRecord $record): void
            {
                throw new \RuntimeException('sink is broken');
            }
        };

        $rt = new RecordingTransport($inner, $throwingSink);

        $response = $rt->request('GET', 'https://example.test/ok');

        self::assertSame(200, $response->status);
        self::assertSame('fine', $response->body);
    }
}
