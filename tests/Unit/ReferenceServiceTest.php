<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Joomla\ApiClient;
use Grafida\Reference\ReferenceRepository;
use Grafida\Reference\ReferenceService;
use Grafida\Site\Site;
use Grafida\Site\SiteRepository;
use Grafida\Site\SiteService;
use Grafida\Tests\Support\TestDatabase;
use Grafida\Tests\Unit\Support\FakeTransport;
use Joomla\Database\DatabaseInterface;

/**
 * gh-42: {@see ReferenceService::fetchedAt()} is what lets the SPA decide
 * whether a site's cached reference data looks stale enough to freshen in
 * the background.
 */
final class ReferenceServiceTest extends TestCase
{
    private DatabaseInterface $db;
    private ReferenceRepository $repository;
    private ReferenceService $service;

    protected function setUp(): void
    {
        $this->db         = TestDatabase::memory();
        $this->repository = new ReferenceRepository($this->db);
        $siteService       = new SiteService(new SiteRepository($this->db), new ApiClient(new FakeTransport()), null);
        $this->service     = new ReferenceService($this->repository, $siteService);
    }

    /** Inserts a bare site row (reference_cache references sites via a foreign key). */
    private function seedSite(): Site
    {
        $now = gmdate('Y-m-d H:i:s');
        $pdo = TestDatabase::connection($this->db);
        $pdo->prepare(
            'INSERT INTO sites (title, base_url, created_at, updated_at) VALUES (?, ?, ?, ?)'
        )->execute(['Site', 'https://example.test', $now, $now]);

        return new Site((int) $pdo->lastInsertId(), 'Site', 'https://example.test', null, null, false);
    }

    public function testReturnsNullWhenNothingIsCached(): void
    {
        $site = $this->seedSite();

        self::assertNull($this->service->fetchedAt($site));
    }

    public function testReturnsTheOldestFetchedAtAcrossAllRefreshableKinds(): void
    {
        $site = $this->seedSite();

        // Deliberately out of order and with distinct timestamps, so a naive
        // "last write wins" implementation would fail this.
        $this->repository->put((int) $site->id, ReferenceService::KIND_TAGS, []);
        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_TAGS, '2026-01-01 12:00:00');

        $this->repository->put((int) $site->id, ReferenceService::KIND_CATEGORIES, []);
        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_CATEGORIES, '2025-06-15 08:30:00');

        $this->repository->put((int) $site->id, ReferenceService::KIND_LEVELS, []);
        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_LEVELS, '2026-03-01 00:00:00');

        $this->repository->put((int) $site->id, ReferenceService::KIND_FIELDS, []);
        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_FIELDS, '2026-02-01 00:00:00');

        $this->repository->put((int) $site->id, ReferenceService::KIND_LANGUAGES, []);
        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_LANGUAGES, '2026-01-15 00:00:00');

        self::assertSame('2025-06-15 08:30:00', $this->service->fetchedAt($site));
    }

    public function testReturnsNullWhenOneRefreshableKindIsMissing(): void
    {
        $site = $this->seedSite();

        // Every kind except tags — a partially warmed cache is, for
        // freshness purposes, no cache.
        $this->repository->put((int) $site->id, ReferenceService::KIND_CATEGORIES, []);
        $this->repository->put((int) $site->id, ReferenceService::KIND_LEVELS, []);
        $this->repository->put((int) $site->id, ReferenceService::KIND_FIELDS, []);
        $this->repository->put((int) $site->id, ReferenceService::KIND_LANGUAGES, []);

        self::assertNull($this->service->fetchedAt($site));
    }

    public function testConfigKindDoesNotInfluenceTheResult(): void
    {
        $site = $this->seedSite();

        // Only KIND_CONFIG is cached — none of the five refreshable kinds —
        // so the result must still be null, not the config's timestamp.
        $this->repository->put((int) $site->id, ReferenceService::KIND_CONFIG, ['unicodeslugs' => true]);

        self::assertNull($this->service->fetchedAt($site));

        // Warm every refreshable kind but leave KIND_CONFIG's timestamp far
        // newer; it must never win, and never lose either.
        foreach (
            [
                ReferenceService::KIND_CATEGORIES,
                ReferenceService::KIND_TAGS,
                ReferenceService::KIND_LEVELS,
                ReferenceService::KIND_FIELDS,
                ReferenceService::KIND_LANGUAGES,
            ] as $kind
        ) {
            $this->repository->put((int) $site->id, $kind, []);
            $this->setFetchedAt((int) $site->id, $kind, '2026-01-01 00:00:00');
        }

        $this->setFetchedAt((int) $site->id, ReferenceService::KIND_CONFIG, '2099-01-01 00:00:00');

        self::assertSame('2026-01-01 00:00:00', $this->service->fetchedAt($site));
    }

    private function setFetchedAt(int $siteId, string $kind, string $fetchedAt): void
    {
        TestDatabase::connection($this->db)
            ->prepare('UPDATE reference_cache SET fetched_at = ? WHERE site_id = ? AND kind = ?')
            ->execute([$fetchedAt, $siteId, $kind]);
    }
}
