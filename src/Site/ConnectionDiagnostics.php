<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Site;

use Grafida\Debug\ArraySink;
use Grafida\Debug\RecordingTransport;
use Grafida\Debug\RequestRecord;
use Grafida\Http\Transport;
use Grafida\Joomla\ApiClient;

/**
 * Backs the Diagnose Connection panel: runs the same API-base probe as
 * "Test Connection", but reports every candidate base it tried — the full
 * request and response, redacted and body-formatted — instead of only the
 * final apiBase-or-error verdict.
 *
 * Builds a throwaway {@see ApiClient} over a {@see RecordingTransport} writing
 * to a private {@see ArraySink}, so a diagnose run works with the Request Log
 * setting switched off and never pollutes the container-shared log. The inner
 * transport must be the *unwrapped* `http.diagnostics` client (see
 * `HttpProvider`) — wrapping an already-recorded transport here would also
 * write the probe's exchanges into the shared Request Log.
 */
final class ConnectionDiagnostics
{
    public function __construct(private readonly Transport $http) {}

    /**
     * @return array{apiBase: ?string, error: ?string, attempts: list<array<string, mixed>>}
     */
    public function run(string $url, string $token): array
    {
        $sink = new ArraySink();
        $api  = new ApiClient(new RecordingTransport($this->http, $sink));

        $apiBase = null;
        $error   = null;

        try {
            $apiBase = $api->probeApiBase($url, $token);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'apiBase'  => $apiBase,
            'error'    => $error,
            'attempts' => array_map(static fn (RequestRecord $r): array => $r->toArray(), $sink->entries()),
        ];
    }
}
