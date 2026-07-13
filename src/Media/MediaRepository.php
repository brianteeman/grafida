<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Media;

use Grafida\Storage\QueryBuilderSupport;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Data-access for offline media blobs (images inserted while editing offline).
 */
final class MediaRepository
{
    use QueryBuilderSupport;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Stores a raw image and returns its new id.
     */
    public function store(int $siteId, ?int $draftId, string $filename, string $mime, string $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $query = $this->db->createQuery()
            ->insert($this->qn('media_blobs'))
            ->columns([
                $this->qn('site_id'),
                $this->qn('draft_id'),
                $this->qn('filename'),
                $this->qn('mime'),
                $this->qn('data'),
                $this->qn('created_at'),
            ])
            ->values(':site, :draft, :name, :mime, :data, :now')
            ->bind(':site', $siteId, ParameterType::INTEGER)
            ->bind(':draft', $draftId, $draftId === null ? ParameterType::NULL : ParameterType::INTEGER)
            ->bind(':name', $filename, ParameterType::STRING)
            ->bind(':mime', $mime, ParameterType::STRING)
            ->bind(':data', $data, ParameterType::LARGE_OBJECT)
            ->bind(':now', $now, ParameterType::STRING);

        $this->db->setQuery($query)->execute();

        return $this->lastInsertId();
    }

    /**
     * @return array{id: int, filename: string, mime: string, data: string, remote_path: ?string, remote_url: ?string}|null
     */
    public function find(int $id): ?array
    {
        $query = $this->db->createQuery()
            ->select([
                $this->qn('id'),
                $this->qn('filename'),
                $this->qn('mime'),
                $this->qn('data'),
                $this->qn('remote_path'),
                $this->qn('remote_url'),
            ])
            ->from($this->qn('media_blobs'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        /** @var array{id: int|string, filename: string, mime: string, data: string, remote_path: string|null, remote_url: string|null}|null $row */
        $row = $this->db->setQuery($query)->loadAssoc();

        if ($row === null) {
            return null;
        }

        return [
            'id'          => (int) $row['id'],
            'filename'    => $row['filename'],
            'mime'        => $row['mime'],
            'data'        => $row['data'],
            'remote_path' => $row['remote_path'] !== null ? $row['remote_path'] : null,
            'remote_url'  => $row['remote_url'] !== null ? $row['remote_url'] : null,
        ];
    }

    /** Records the remote path/URL after a successful upload. */
    public function markUploaded(int $id, string $remotePath, string $remoteUrl): void
    {
        $query = $this->db->createQuery()
            ->update($this->qn('media_blobs'))
            ->set($this->qn('remote_path') . ' = :path')
            ->set($this->qn('remote_url') . ' = :url')
            ->where($this->qn('id') . ' = :id')
            ->bind(':path', $remotePath, ParameterType::STRING)
            ->bind(':url', $remoteUrl, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /** Returns the data: URI representation of a stored blob. */
    public function dataUri(int $id): ?string
    {
        $blob = $this->find($id);

        if ($blob === null) {
            return null;
        }

        return 'data:' . $blob['mime'] . ';base64,' . base64_encode($blob['data']);
    }
}
