<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Site;

use Grafida\Storage\QueryBuilderSupport;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Data-access for the per-site cached favicon (`site_favicons` table).
 */
final class FaviconRepository
{
    use QueryBuilderSupport;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /** Stores (or replaces) the cached favicon for a site. */
    public function put(int $siteId, string $mime, string $data): void
    {
        $now = gmdate('Y-m-d H:i:s');

        // UPSERT: no builder vocabulary for ON CONFLICT. excluded.* means each
        // placeholder is bound exactly once.
        $query = $this->db->createQuery()
            ->setQuery(
                'INSERT INTO site_favicons (site_id, mime, data, fetched_at) '
                . 'VALUES (:site, :mime, :data, :now) '
                . 'ON CONFLICT(site_id) DO UPDATE SET '
                . 'mime = excluded.mime, data = excluded.data, fetched_at = excluded.fetched_at'
            )
            ->bind(':site', $siteId, ParameterType::INTEGER)
            ->bind(':mime', $mime, ParameterType::STRING)
            ->bind(':data', $data, ParameterType::LARGE_OBJECT)
            ->bind(':now', $now, ParameterType::STRING);

        $this->db->setQuery($query)->execute();
    }

    /**
     * @return array{mime: string, data: string}|null
     */
    public function find(int $siteId): ?array
    {
        $query = $this->db->createQuery()
            ->select([
                $this->qn('mime'),
                $this->qn('data'),
            ])
            ->from($this->qn('site_favicons'))
            ->where($this->qn('site_id') . ' = :site')
            ->bind(':site', $siteId, ParameterType::INTEGER);

        /** @var array{mime: string, data: string}|null $row */
        $row = $this->db->setQuery($query)->loadAssoc();

        if ($row === null) {
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
