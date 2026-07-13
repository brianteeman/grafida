<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Reference;

use Grafida\Storage\QueryBuilderSupport;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Stores and retrieves per-site cached reference data (categories, tags, access
 * levels, field definitions) as JSON payloads.
 */
final class ReferenceRepository
{
    use QueryBuilderSupport;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * @return array{payload: array<mixed>, fetched_at: string}|null
     */
    public function get(int $siteId, string $kind): ?array
    {
        $query = $this->db->createQuery()
            ->select([
                $this->qn('payload'),
                $this->qn('fetched_at'),
            ])
            ->from($this->qn('reference_cache'))
            ->where($this->qn('site_id') . ' = :site')
            ->where($this->qn('kind') . ' = :kind')
            ->bind(':site', $siteId, ParameterType::INTEGER)
            ->bind(':kind', $kind, ParameterType::STRING);

        /** @var array{payload: string, fetched_at: string}|null $row */
        $row = $this->db->setQuery($query)->loadAssoc();

        if ($row === null) {
            return null;
        }

        $decoded = json_decode($row['payload'], true);

        return [
            'payload'    => is_array($decoded) ? $decoded : [],
            'fetched_at' => $row['fetched_at'],
        ];
    }

    /** @param array<mixed> $payload */
    public function put(int $siteId, string $kind, array $payload): void
    {
        $json = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $now  = gmdate('Y-m-d H:i:s');

        // UPSERT with a composite conflict target: no builder vocabulary for
        // ON CONFLICT. excluded.* means each placeholder is bound exactly once.
        $query = $this->db->createQuery()
            ->setQuery(
                'INSERT INTO reference_cache (site_id, kind, payload, fetched_at) VALUES (:s, :k, :p, :t) '
                . 'ON CONFLICT(site_id, kind) DO UPDATE SET '
                . 'payload = excluded.payload, fetched_at = excluded.fetched_at'
            )
            ->bind(':s', $siteId, ParameterType::INTEGER)
            ->bind(':k', $kind, ParameterType::STRING)
            ->bind(':p', $json, ParameterType::STRING)
            ->bind(':t', $now, ParameterType::STRING);

        $this->db->setQuery($query)->execute();
    }

    public function getEditorCss(int $siteId): ?string
    {
        $query = $this->db->createQuery()
            ->select($this->qn('css'))
            ->from($this->qn('editor_css_cache'))
            ->where($this->qn('site_id') . ' = :site')
            ->bind(':site', $siteId, ParameterType::INTEGER);

        $value = $this->db->setQuery($query)->loadResult();

        return $this->toStringOrNull($value);
    }

    public function putEditorCss(int $siteId, string $css): void
    {
        $now = gmdate('Y-m-d H:i:s');

        // UPSERT: no builder vocabulary for ON CONFLICT.
        $query = $this->db->createQuery()
            ->setQuery(
                'INSERT INTO editor_css_cache (site_id, css, fetched_at) VALUES (:s, :c, :t) '
                . 'ON CONFLICT(site_id) DO UPDATE SET css = excluded.css, fetched_at = excluded.fetched_at'
            )
            ->bind(':s', $siteId, ParameterType::INTEGER)
            ->bind(':c', $css, ParameterType::STRING)
            ->bind(':t', $now, ParameterType::STRING);

        $this->db->setQuery($query)->execute();
    }
}
