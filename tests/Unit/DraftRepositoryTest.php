<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit;

use Grafida\Article\Draft;
use Grafida\Article\DraftRepository;
use Grafida\Storage\Database;
use Grafida\Storage\Migrator;
use PDO;

final class DraftRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connect(':memory:');
        (new Migrator($this->pdo))->migrate();
        $this->pdo->exec(
            'INSERT INTO sites (id, title, base_url, created_at, updated_at) '
            . "VALUES (1, 'Site', 'https://example.com', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
        );
    }

    private function repo(): DraftRepository
    {
        return new DraftRepository($this->pdo);
    }

    private function sample(): Draft
    {
        return new Draft(
            id: null,
            siteId: 1,
            remoteId: null,
            title: 'Hello',
            alias: 'hello',
            catid: null,
            access: 1,
            language: '*',
            state: 1,
            html: '<p>Body</p>',
            fields: ['colour' => 'blue'],
            tags: ['news'],
            images: ['image_intro' => 'images/a.png'],
            metadesc: 'desc',
            metakey: 'key',
        );
    }

    public function testInsertPersistsAndRoundTrips(): void
    {
        $id = $this->repo()->insert($this->sample());

        self::assertGreaterThan(0, $id);

        $back = $this->repo()->find($id);

        self::assertNotNull($back);
        self::assertSame('Hello', $back->title);
        self::assertSame(['colour' => 'blue'], $back->fields);
        self::assertSame(['news'], $back->tags);
        self::assertSame(['image_intro' => 'images/a.png'], $back->images);
    }

    public function testUpdateChangesStoredValues(): void
    {
        $repo = $this->repo();
        $id   = $repo->insert($this->sample());

        $draft = $repo->find($id);
        self::assertNotNull($draft);

        $draft->title = 'Changed';
        $draft->html  = '<p>New</p>';
        $repo->update($draft);

        $back = $repo->find($id);
        self::assertNotNull($back);
        self::assertSame('Changed', $back->title);
        self::assertSame('<p>New</p>', $back->html);
        self::assertSame(1, $back->siteId);
    }

    public function testSetRemoteIdAndDelete(): void
    {
        $repo = $this->repo();
        $id   = $repo->insert($this->sample());

        $repo->setRemoteId($id, 42);
        self::assertSame(42, $repo->find($id)?->remoteId);

        $repo->delete($id);
        self::assertNull($repo->find($id));
        self::assertCount(0, $repo->forSite(1));
    }
}
