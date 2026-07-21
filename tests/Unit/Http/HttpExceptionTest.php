<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Http;

use Grafida\Http\HttpException;
use Grafida\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class HttpExceptionTest extends TestCase
{
    /** @return list<array{int}> */
    public static function connectivityErrnoProvider(): array
    {
        return [
            'CURLE_COULDNT_RESOLVE_HOST' => [6],
            'CURLE_COULDNT_CONNECT'      => [7],
            'CURLE_OPERATION_TIMEDOUT'   => [28],
        ];
    }

    #[DataProvider('connectivityErrnoProvider')]
    public function testIsConnectivityFailureIsTrueForConnectivityErrnos(int $errno): void
    {
        $e = new HttpException('boom', $errno);

        self::assertTrue($e->isConnectivityFailure());
    }

    public function testIsConnectivityFailureIsFalseForTheStreamFallbackErrno(): void
    {
        // The stream-wrapper fallback (no ext-curl) gives us no machine-readable
        // cause, so it always constructs with curlErrno 0 and must never be
        // classified as a connectivity failure.
        $e = new HttpException('boom');

        self::assertSame(0, $e->curlErrno);
        self::assertFalse($e->isConnectivityFailure());
    }

    public function testIsConnectivityFailureIsFalseForATlsError(): void
    {
        // CURLE_PEER_FAILED_VERIFICATION: we *did* talk to something, just not
        // securely — telling the user to check their internet connection over
        // a bad certificate would be actively misleading.
        $e = new HttpException('certificate problem', 60);

        self::assertFalse($e->isConnectivityFailure());
    }

    public function testSingleArgumentConstructionStillWorks(): void
    {
        // Backward compatibility: every existing call site passes only a message.
        $e = new HttpException('HTTP request failed for https://example.test/');

        self::assertSame('HTTP request failed for https://example.test/', $e->getMessage());
        self::assertSame(0, $e->curlErrno);
    }
}
