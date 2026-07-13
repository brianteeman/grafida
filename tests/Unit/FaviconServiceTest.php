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
use Grafida\Site\FaviconRepository;
use Grafida\Site\FaviconService;
use Grafida\Site\Site;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\FakeTransport;
use Joomla\Database\DatabaseInterface;

final class FaviconServiceTest extends TestCase
{
    private DatabaseInterface $db;

    protected function setUp(): void
    {
        $this->db = TestDatabase::memory();

        // A site row must exist for the FK-constrained favicon cache.
        TestDatabase::connection($this->db)->exec(
            "INSERT INTO sites (id, title, base_url, api_base, created_at, updated_at) "
            . "VALUES (7, 'Example', 'https://example.com', 'https://example.com/index.php/api', '2026-01-01', '2026-01-01')"
        );
    }

    private function site(): Site
    {
        return new Site(7, 'Example', 'https://example.com', 'https://example.com/index.php/api', null, false);
    }

    public function testPicksLargestDeclaredIconAndCachesIt(): void
    {
        $html = <<<HTML
            <html><head>
              <link rel="icon" sizes="16x16" href="/small.png">
              <link rel="apple-touch-icon" sizes="180x180" href="https://cdn.example.com/big.png">
            </head><body></body></html>
            HTML;

        $transport = (new FakeTransport())
            ->on('https://example.com/', new HttpResponse(200, $html))
            ->on('https://cdn.example.com/big.png', new HttpResponse(200, 'PNGDATA', ['Content-Type' => 'image/png']));

        $repo    = new FaviconRepository($this->db);
        $service = new FaviconService($repo, $transport);
        $service->sync($this->site());

        $row = $repo->find(7);
        self::assertNotNull($row);
        self::assertSame('image/png', $row['mime']);
        self::assertSame('PNGDATA', $row['data']);
        self::assertSame('data:image/png;base64,' . base64_encode('PNGDATA'), $service->dataUri(7));
    }

    public function testFallsBackToConventionalFaviconWhenNoneDeclared(): void
    {
        $transport = (new FakeTransport())
            ->on('https://example.com/', new HttpResponse(200, '<html><head></head><body></body></html>'))
            ->on('https://example.com/favicon.ico', new HttpResponse(200, 'ICODATA', ['Content-Type' => 'image/x-icon']));

        $repo    = new FaviconRepository($this->db);
        $service = new FaviconService($repo, $transport);
        $service->sync($this->site());

        $row = $repo->find(7);
        self::assertNotNull($row);
        self::assertSame('image/x-icon', $row['mime']);
        self::assertSame('ICODATA', $row['data']);
    }

    public function testUnreachableSiteLeavesNoCachedIcon(): void
    {
        $transport = (new FakeTransport(new HttpResponse(404, '')))
            ->throwFor('https://example.com/');

        $repo    = new FaviconRepository($this->db);
        $service = new FaviconService($repo, $transport);
        $service->sync($this->site());

        self::assertNull($repo->find(7));
        self::assertNull($service->dataUri(7));
    }
}
