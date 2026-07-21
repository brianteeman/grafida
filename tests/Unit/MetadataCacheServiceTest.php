<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Reference\MetadataCacheService;
use Grafida\Reference\ReferenceRepository;
use Grafida\Site\Site;
use Grafida\Storage\SettingsRepository;
use Grafida\Tests\Support\TestDatabase;
use Joomla\Database\DatabaseInterface;

/**
 * gh-42 round 2: the two site-metadata cache preferences and the opt-in
 * startup cache reset.
 */
final class MetadataCacheServiceTest extends TestCase
{
    private DatabaseInterface $db;
    private ReferenceRepository $repository;

    protected function setUp(): void
    {
        $this->db         = TestDatabase::memory();
        $this->repository = new ReferenceRepository($this->db);
    }

    /** A fresh service instance over the same database, mirroring how the container hands one out per request. */
    private function service(): MetadataCacheService
    {
        return new MetadataCacheService(new SettingsRepository($this->db), $this->repository);
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

    private function countReferenceCacheRows(): int
    {
        $pdo = TestDatabase::connection($this->db);

        return (int) $pdo->query('SELECT COUNT(*) FROM reference_cache')->fetchColumn();
    }

    public function testDefaultsOnAVirginDatabase(): void
    {
        $service = $this->service();

        self::assertFalse($service->resetOnStart());
        self::assertSame(60, $service->ttlMinutes());
    }

    public function testSetResetOnStartPersistsAcrossAFreshServiceInstance(): void
    {
        $this->service()->setResetOnStart(true);

        self::assertTrue($this->service()->resetOnStart());
    }

    public function testSetTtlMinutesAcceptsEveryListedChoice(): void
    {
        foreach (MetadataCacheService::TTL_CHOICES as $minutes) {
            $service = $this->service();

            self::assertSame($minutes, $service->setTtlMinutes($minutes));
            self::assertSame($minutes, $this->service()->ttlMinutes());
        }
    }

    public function testSetTtlMinutesClampsAnUnlistedValueToTheDefault(): void
    {
        $service = $this->service();

        self::assertSame(MetadataCacheService::DEFAULT_TTL_MINUTES, $service->setTtlMinutes(7));
        self::assertSame(MetadataCacheService::DEFAULT_TTL_MINUTES, $this->service()->ttlMinutes());
    }

    public function testTtlMinutesClampsAValueWrittenOutOfBand(): void
    {
        // Simulate a hand-edited settings row bypassing the service's own clamp.
        (new SettingsRepository($this->db))->set(MetadataCacheService::SETTING_TTL_MINUTES, '999');

        self::assertSame(MetadataCacheService::DEFAULT_TTL_MINUTES, $this->service()->ttlMinutes());
    }

    public function testResetIfRequestedDoesNothingWhenThePreferenceIsOff(): void
    {
        self::assertFalse($this->service()->resetIfRequested());
    }

    public function testResetIfRequestedClearsOnceAndOnlyOncePerInstance(): void
    {
        $site = $this->seedSite();
        $this->repository->put((int) $site->id, 'categories', [['id' => 1, 'title' => 'A']]);
        self::assertSame(1, $this->countReferenceCacheRows());

        $service = $this->service();
        $service->setResetOnStart(true);

        self::assertTrue($service->resetIfRequested());
        self::assertSame(0, $this->countReferenceCacheRows());

        // Seed a new row and confirm the SAME instance will not clear it again —
        // the once-per-process guarantee resetIfRequested() exists for.
        $this->repository->put((int) $site->id, 'categories', [['id' => 2, 'title' => 'B']]);
        self::assertSame(1, $this->countReferenceCacheRows());

        self::assertFalse($service->resetIfRequested());
        self::assertSame(1, $this->countReferenceCacheRows());
    }
}
