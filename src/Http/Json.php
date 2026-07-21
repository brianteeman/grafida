<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http;

use Boson\Component\Http\Response;
use Boson\Contracts\Http\ResponseInterface;

/**
 * Builds JSON HTTP responses for the internal API.
 */
final class Json
{
    /**
     * @param mixed $data
     */
    public static function ok($data = null, int $status = 200): ResponseInterface
    {
        return self::response(['ok' => true, 'data' => $data], $status);
    }

    /**
     * @param array<string, mixed> $extra Extra fields merged into the payload (e.g. an error code).
     */
    public static function error(string $message, int $status = 400, array $extra = []): ResponseInterface
    {
        return self::response(['ok' => false, 'error' => $message] + $extra, $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function response(array $payload, int $status): ResponseInterface
    {
        $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return new Response((string) $body, $status, [
            'Content-Type' => 'application/json; charset=utf-8',
            // Nothing the internal API answers is ever reusable: it is the live
            // state of the local database and of the remote site. The webview
            // caches custom-scheme GETs heuristically when a response says
            // nothing about freshness, and that cache survives an app restart,
            // so a response could be reused without our PHP ever running (found
            // while investigating gh-35). The SPA also asks for `cache: 'no-store'`;
            // this makes the responses themselves say so, for any caller that
            // does not go through apiFetch().
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma'        => 'no-cache',
        ]);
    }
}
