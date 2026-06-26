<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Site;

use PDO;

/**
 * Data-access for the per-site cached favicon (`site_favicons` table).
 */
final class FaviconRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    /** Stores (or replaces) the cached favicon for a site. */
    public function put(int $siteId, string $mime, string $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO site_favicons (site_id, mime, data, fetched_at) '
            . 'VALUES (:site, :mime, :data, :now) '
            . 'ON CONFLICT(site_id) DO UPDATE SET mime = :mime, data = :data, fetched_at = :now'
        );
        $stmt->bindValue(':site', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':mime', $mime);
        $stmt->bindValue(':data', $data, PDO::PARAM_LOB);
        $stmt->bindValue(':now', gmdate('Y-m-d H:i:s'));
        $stmt->execute();
    }

    /**
     * @return array{mime: string, data: string}|null
     */
    public function find(int $siteId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT mime, data FROM site_favicons WHERE site_id = ?');
        $stmt->execute([$siteId]);

        /** @var array{mime: string, data: string}|false $row */
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return ['mime' => $row['mime'], 'data' => $row['data']];
    }

    /** Returns the cached favicon as a data: URI, or null when none is stored. */
    public function dataUri(int $siteId): ?string
    {
        $row = $this->find($siteId);

        if ($row === null) {
            return null;
        }

        return 'data:' . $row['mime'] . ';base64,' . base64_encode($row['data']);
    }
}
