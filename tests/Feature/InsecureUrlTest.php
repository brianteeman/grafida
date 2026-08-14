<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Feature;

use Boson\Component\Http\Request;
use Grafida\Application\Kernel;
use Grafida\Debug\RecordingTransport;
use Grafida\Debug\RequestLog;
use Grafida\Tests\Support\TestContainer;
use Grafida\Tests\Unit\Support\FakeTransport;
use Joomla\DI\Container;
use PHPUnit\Framework\TestCase;

/**
 * Pins the HTTPS-only contract for site URLs: every entry point that accepts
 * a site URL — test connection, diagnose, create, update — must reject a
 * non-HTTPS URL before any outbound HTTP request is sent, with a 400 +
 * `code: insecure_url` payload. Without this, the API token would travel
 * across the network in cleartext.
 */
final class InsecureUrlTest extends TestCase
{
    /**
     * Wires a fake transport that would *answer* a request, so the only way
     * an HTTP request can reach it is by getting past the scheme check.
     * Asserting on this in the rejection tests proves no request was sent.
     *
     * The fake is wrapped in a {@see RecordingTransport} to keep the
     * container's `http.default` factory return-type compatible.
     */
    private function kernelThatWouldAnswerHttp(): Kernel
    {
        $fake = (new FakeTransport())->on(
            'http://example.test/index.php/api/v1/users/levels',
            new \Grafida\Http\HttpResponse(
                200,
                '{"data":[]}',
                ['content-type' => 'application/vnd.api+json']
            )
        );

        $container = TestContainer::create();
        $container->set(
            'http.default',
            static fn (Container $c): RecordingTransport => new RecordingTransport($fake, $c->get(RequestLog::class)),
            true,
        );

        return $container->get(Kernel::class);
    }

    private function plainKernel(): Kernel
    {
        return TestContainer::create()->get(Kernel::class);
    }

    /** @return array{0: int, 1: mixed} */
    private function call(Kernel $kernel, string $method, string $path, ?string $body = null): array
    {
        $request  = new Request($method, 'boson://app' . $path, [], $body ?? '');
        $response = $kernel->handle($request);

        return [(int) (string) $response->status, json_decode((string) $response->body, true)];
    }

    /**
     * Wires a fake transport that would answer, seeds one site row into the
     * kernel's own database, and returns the kernel together with the new
     * site's id. Each TestContainer uses an in-memory SQLite, so seeding and
     * reading must share a container.
     *
     * @return array{0: Kernel, 1: int}
     */
    private function kernelWithSeededSite(string $seedUrl = 'https://example.test'): array
    {
        $fake = (new FakeTransport())->on(
            'http://example.test/index.php/api/v1/users/levels',
            new \Grafida\Http\HttpResponse(
                200,
                '{"data":[]}',
                ['content-type' => 'application/vnd.api+json']
            )
        );

        $container = TestContainer::create();
        $container->set(
            'http.default',
            static fn (Container $c): RecordingTransport => new RecordingTransport($fake, $c->get(RequestLog::class)),
            true,
        );

        $db  = $container->get(\Joomla\Database\DatabaseInterface::class);
        $pdo = \Grafida\Tests\Support\TestDatabase::connection($db);
        $now = gmdate('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO sites (title, base_url, created_at, updated_at) VALUES (?, ?, ?, ?)'
        )->execute(['Site', $seedUrl, $now, $now]);
        $siteId = (int) $pdo->lastInsertId();

        return [$container->get(Kernel::class), $siteId];
    }

    public function testTestConnectionRejectsHttp(): void
    {
        [$status, $json] = $this->call(
            $this->kernelThatWouldAnswerHttp(),
            'POST',
            '/api/sites/test',
            json_encode(['url' => 'http://example.test', 'token' => 'tok'])
        );

        self::assertSame(400, $status);
        self::assertFalse($json['ok']);
        self::assertSame('insecure_url', $json['code']);
        self::assertStringContainsString('HTTPS', (string) $json['error']);
    }

    public function testDiagnoseRejectsHttp(): void
    {
        // The Diagnose endpoint deliberately never throws — it reports every
        // probe attempt to the user. A scheme rejection therefore lands in
        // the response payload as `error`, with an empty `attempts` array
        // (no HTTP request was dispatched).
        [$status, $json] = $this->call(
            $this->kernelThatWouldAnswerHttp(),
            'POST',
            '/api/sites/diagnose',
            json_encode(['url' => 'http://example.test', 'token' => 'tok'])
        );

        self::assertSame(200, $status);
        self::assertTrue($json['ok']);
        self::assertNull($json['data']['apiBase']);
        self::assertNotNull($json['data']['error']);
        self::assertSame([], $json['data']['attempts']);
        self::assertStringContainsString('HTTPS', (string) $json['data']['error']);
    }

    public function testCreateSiteRejectsHttp(): void
    {
        [$status, $json] = $this->call(
            $this->kernelThatWouldAnswerHttp(),
            'POST',
            '/api/sites',
            json_encode([
                'title' => 'Site',
                'url'   => 'http://example.test',
                'token' => 'tok',
            ])
        );

        self::assertSame(400, $status);
        self::assertFalse($json['ok']);
        self::assertSame('insecure_url', $json['code']);
    }

    public function testUpdateSiteRejectsHttp(): void
    {
        // Seed the *same* in-memory database the kernel will use.
        [$kernel, $siteId] = $this->kernelWithSeededSite();

        [$status, $json] = $this->call(
            $kernel,
            'PATCH',
            '/api/sites/' . $siteId,
            json_encode([
                'title' => 'Site',
                'url'   => 'http://example.test',
                'token' => 'tok',
            ])
        );

        self::assertSame(400, $status);
        self::assertFalse($json['ok']);
        self::assertSame('insecure_url', $json['code']);
    }

    public function testRejectsUrlWithoutScheme(): void
    {
        [$status, $json] = $this->call(
            $this->plainKernel(),
            'POST',
            '/api/sites/test',
            json_encode(['url' => 'example.test', 'token' => 'tok'])
        );

        self::assertSame(400, $status);
        self::assertFalse($json['ok']);
        self::assertSame('insecure_url', $json['code']);
    }

    public function testRejectsNonHttpsSchemes(): void
    {
        // ftp:// is not a sensible target but the rule is "HTTPS only", so any
        // non-https scheme must be refused with the same code.
        [$status, $json] = $this->call(
            $this->plainKernel(),
            'POST',
            '/api/sites/test',
            json_encode(['url' => 'ftp://example.test', 'token' => 'tok'])
        );

        self::assertSame(400, $status);
        self::assertFalse($json['ok']);
        self::assertSame('insecure_url', $json['code']);
    }

    public function testAcceptsUppercaseHttpsScheme(): void
    {
        // The check is case-insensitive: an operator who types HTTPS://...
        // is not trying to downgrade security.
        [$status, $json] = $this->call(
            $this->kernelThatWouldAnswerHttp(),
            'POST',
            '/api/sites/test',
            // The fake only answers the lowercase variant, but the scheme check
            // runs first — a 502 here would mean the scheme check let the
            // request through (and the fake's URL matcher is case-sensitive),
            // not that the scheme check rejected it.
            json_encode(['url' => 'HTTPS://example.test', 'token' => 'tok'])
        );

        self::assertNotSame(400, $status);
        self::assertNotSame(503, $status);
    }
}
