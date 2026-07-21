<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Debug;

use Grafida\Http\HttpException;
use Grafida\Http\HttpResponse;
use Grafida\Http\Transport;

/**
 * Decorates a {@see Transport}, timing every call and handing a
 * {@see RequestRecord} of it to a {@see RecordSink}.
 *
 * This is what lets Diagnose Connection capture a probe's exchanges with the
 * Request Log setting switched off: it builds a throwaway `ApiClient` over a
 * recorder writing to an {@see ArraySink} of its own, while the shared
 * `http.default`/`http.short`/`http.reference` transports record into the
 * container-shared {@see RequestLog}.
 */
final class RecordingTransport implements Transport
{
    public function __construct(
        private readonly Transport $inner,
        private readonly RecordSink $sink,
    ) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $started = microtime(true);

        [$cappedRequestBody] = BodyFormatter::cap($body);

        try {
            $response = $this->inner->request($method, $url, $headers, $body);
        } catch (HttpException $e) {
            $this->safeRecord(new RequestRecord(
                timestamp: gmdate('Y-m-d H:i:s'),
                durationMs: $this->elapsedMs($started),
                method: $method,
                url: $url,
                requestHeaders: $headers,
                requestBody: $cappedRequestBody,
                status: null,
                responseHeaders: [],
                responseBody: null,
                error: $e->getMessage(),
            ));

            throw $e;
        }

        [$cappedResponseBody] = BodyFormatter::cap($response->body);

        $this->safeRecord(new RequestRecord(
            timestamp: gmdate('Y-m-d H:i:s'),
            durationMs: $this->elapsedMs($started),
            method: $method,
            url: $url,
            requestHeaders: $headers,
            requestBody: $cappedRequestBody,
            status: $response->status,
            responseHeaders: $response->headers,
            responseBody: $cappedResponseBody,
            error: null,
        ));

        return $response;
    }

    private function elapsedMs(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }

    /** Recording itself must never break a request. */
    private function safeRecord(RequestRecord $record): void
    {
        try {
            $this->sink->record($record);
        } catch (\Throwable) {
            // Deliberately swallowed.
        }
    }
}
