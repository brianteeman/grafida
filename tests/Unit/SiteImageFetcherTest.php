<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Http\HttpResponse;
use Grafida\Media\SiteImageException;
use Grafida\Media\SiteImageFetcher;
use Grafida\Site\Site;
use Grafida\Tests\Unit\Support\FakeTransport;

final class SiteImageFetcherTest extends TestCase
{
    private function site(): Site
    {
        return new Site(7, 'Example', 'https://example.com', 'https://example.com/index.php/api', null, false);
    }

    /** A real 1x1 PNG — the fetcher sniffs the bytes, so a placeholder string will not do. */
    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    public function testResolvesASiteRelativeSrcAgainstTheSiteRoot(): void
    {
        $http = new FakeTransport();
        $http->on('https://example.com/images/grafida/photo.png', new HttpResponse(200, $this->pngBytes()));

        $result = (new SiteImageFetcher($http))->fetch($this->site(), 'images/grafida/photo.png');

        $this->assertSame('image/png', $result['mime']);
        $this->assertStringStartsWith('data:image/png;base64,', $result['dataUri']);
        $this->assertSame($this->pngBytes(), base64_decode(substr($result['dataUri'], \strlen('data:image/png;base64,'))));
    }

    public function testResolvesARootRelativeSrc(): void
    {
        $http = new FakeTransport();
        $http->on('https://example.com/images/photo.png', new HttpResponse(200, $this->pngBytes()));

        $result = (new SiteImageFetcher($http))->fetch($this->site(), '/images/photo.png');

        $this->assertSame('image/png', $result['mime']);
    }

    public function testAcceptsAnAbsoluteUrlOnTheSiteItself(): void
    {
        $http = new FakeTransport();
        $http->on('https://example.com/images/photo.png', new HttpResponse(200, $this->pngBytes()));

        $result = (new SiteImageFetcher($http))->fetch($this->site(), 'https://example.com/images/photo.png');

        $this->assertSame('image/png', $result['mime']);
    }

    public function testRefusesAnImageOnAnotherHost(): void
    {
        $http = new FakeTransport();

        $this->expectException(SiteImageException::class);

        try {
            (new SiteImageFetcher($http))->fetch($this->site(), 'https://evil.example.net/a.png');
        } catch (SiteImageException $e) {
            $this->assertSame(403, $e->httpStatus);
            $this->assertSame([], $http->requests, 'an off-site URL must never be requested');

            throw $e;
        }
    }

    public function testRefusesANonHttpScheme(): void
    {
        $this->expectException(SiteImageException::class);

        (new SiteImageFetcher(new FakeTransport()))->fetch($this->site(), 'file:///etc/passwd');
    }

    public function testRefusesAResponseThatIsNotAnImage(): void
    {
        $http = new FakeTransport();
        $http->on('https://example.com/a.png', new HttpResponse(200, '<html>Not found, but with a 200</html>'));

        $this->expectException(SiteImageException::class);

        try {
            (new SiteImageFetcher($http))->fetch($this->site(), 'a.png');
        } catch (SiteImageException $e) {
            $this->assertSame(415, $e->httpStatus);

            throw $e;
        }
    }

    public function testReportsAMissingImage(): void
    {
        $http = new FakeTransport(new HttpResponse(404, ''));

        $this->expectException(SiteImageException::class);

        try {
            (new SiteImageFetcher($http))->fetch($this->site(), 'gone.png');
        } catch (SiteImageException $e) {
            $this->assertSame(404, $e->httpStatus);

            throw $e;
        }
    }

    public function testReportsATransportFailure(): void
    {
        $http = new FakeTransport();
        $http->throwFor('https://example.com/a.png');

        $this->expectException(SiteImageException::class);

        try {
            (new SiteImageFetcher($http))->fetch($this->site(), 'a.png');
        } catch (SiteImageException $e) {
            $this->assertSame(502, $e->httpStatus);

            throw $e;
        }
    }
}
