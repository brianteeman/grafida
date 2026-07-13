<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Article;

use Grafida\Storage\QueryBuilderSupport;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Data-access for locally stored article drafts.
 */
final class DraftRepository
{
    use QueryBuilderSupport;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /** @return list<Draft> */
    public function forSite(int $siteId): array
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('drafts'))
            ->where($this->qn('site_id') . ' = :site')
            ->order($this->qn('updated_at') . ' DESC')
            ->bind(':site', $siteId, ParameterType::INTEGER);

        /** @var list<array{id?: int|string|null, site_id: int|string, remote_id: int|string|null, title: string, alias: string, catid: int|string|null, access: int|string, language: string, state: int|string, html: string, fields_json: string, tags_json: string, images_json: string, metadesc?: string, metakey?: string}> $rows */
        $rows = $this->db->setQuery($query)->loadAssocList();

        return array_values(array_map(static fn (array $r): Draft => Draft::fromRow($r), $rows));
    }

    /** Finds the draft (if any) that mirrors a given remote article on a site. */
    public function findByRemote(int $siteId, int $remoteId): ?Draft
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('drafts'))
            ->where($this->qn('site_id') . ' = :site')
            ->where($this->qn('remote_id') . ' = :remote')
            ->setLimit(1)
            ->bind(':site', $siteId, ParameterType::INTEGER)
            ->bind(':remote', $remoteId, ParameterType::INTEGER);

        /** @var array{id?: int|string|null, site_id: int|string, remote_id: int|string|null, title: string, alias: string, catid: int|string|null, access: int|string, language: string, state: int|string, html: string, fields_json: string, tags_json: string, images_json: string, metadesc?: string, metakey?: string}|null $row */
        $row = $this->db->setQuery($query)->loadAssoc();

        return $row !== null ? Draft::fromRow($row) : null;
    }

    public function find(int $id): ?Draft
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('drafts'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        /** @var array{id?: int|string|null, site_id: int|string, remote_id: int|string|null, title: string, alias: string, catid: int|string|null, access: int|string, language: string, state: int|string, html: string, fields_json: string, tags_json: string, images_json: string, metadesc?: string, metakey?: string}|null $row */
        $row = $this->db->setQuery($query)->loadAssoc();

        return $row !== null ? Draft::fromRow($row) : null;
    }

    /** Inserts a new draft and returns its id. */
    public function insert(Draft $draft): int
    {
        $now  = gmdate('Y-m-d H:i:s');
        $cols = $this->columns($draft);

        $query = $this->db->createQuery()
            ->insert($this->qn('drafts'))
            ->columns([
                ...array_map(fn (array $c): string => $this->qn($c['column']), $cols),
                $this->qn('created_at'),
                $this->qn('updated_at'),
            ])
            ->values(
                implode(', ', array_map(static fn (array $c): string => $c['placeholder'], $cols))
                . ', :created_at, :updated_at'
            )
            ->bind(':created_at', $now, ParameterType::STRING)
            ->bind(':updated_at', $now, ParameterType::STRING);

        foreach ($cols as $i => $c) {
            $query->bind($c['placeholder'], $cols[$i]['value'], $c['type']);
        }

        $this->db->setQuery($query)->execute();

        return $this->lastInsertId();
    }

    public function update(Draft $draft): void
    {
        if ($draft->id === null) {
            throw new \InvalidArgumentException('Cannot update a draft without an id.');
        }

        $now  = gmdate('Y-m-d H:i:s');
        $id   = $draft->id;
        $cols = $this->columns($draft);

        $query = $this->db->createQuery()->update($this->qn('drafts'));

        foreach ($cols as $c) {
            $query->set($this->qn($c['column']) . ' = ' . $c['placeholder']);
        }

        // site_id is updatable: a draft can be re-pointed at another site (which
        // also unlinks it from any remote article — see DraftController::saveDraft).
        $query->set($this->qn('updated_at') . ' = :now')
            ->where($this->qn('id') . ' = :id')
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        foreach ($cols as $i => $c) {
            $query->bind($c['placeholder'], $cols[$i]['value'], $c['type']);
        }

        $this->db->setQuery($query)->execute();
    }

    public function setRemoteId(int $id, int $remoteId): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $query = $this->db->createQuery()
            ->update($this->qn('drafts'))
            ->set($this->qn('remote_id') . ' = :remote_id')
            ->set($this->qn('updated_at') . ' = :now')
            ->where($this->qn('id') . ' = :id')
            ->bind(':remote_id', $remoteId, ParameterType::INTEGER)
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    public function delete(int $id): void
    {
        $query = $this->db->createQuery()
            ->delete($this->qn('drafts'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /**
     * @return list<array{column: string, placeholder: string, value: mixed, type: string}>
     */
    private function columns(Draft $draft): array
    {
        $fieldsJson = json_encode($draft->fields, \JSON_UNESCAPED_UNICODE);
        $tagsJson   = json_encode($draft->tags, \JSON_UNESCAPED_UNICODE);
        $imagesJson = json_encode($draft->images, \JSON_UNESCAPED_UNICODE);

        return [
            ['column' => 'site_id', 'placeholder' => ':site_id', 'value' => $draft->siteId, 'type' => ParameterType::INTEGER],
            ['column' => 'remote_id', 'placeholder' => ':remote_id', 'value' => $draft->remoteId, 'type' => $draft->remoteId === null ? ParameterType::NULL : ParameterType::INTEGER],
            ['column' => 'title', 'placeholder' => ':title', 'value' => $draft->title, 'type' => ParameterType::STRING],
            ['column' => 'alias', 'placeholder' => ':alias', 'value' => $draft->alias, 'type' => ParameterType::STRING],
            ['column' => 'catid', 'placeholder' => ':catid', 'value' => $draft->catid, 'type' => $draft->catid === null ? ParameterType::NULL : ParameterType::INTEGER],
            ['column' => 'access', 'placeholder' => ':access', 'value' => $draft->access, 'type' => ParameterType::INTEGER],
            ['column' => 'language', 'placeholder' => ':language', 'value' => $draft->language, 'type' => ParameterType::STRING],
            ['column' => 'state', 'placeholder' => ':state', 'value' => $draft->state, 'type' => ParameterType::INTEGER],
            ['column' => 'html', 'placeholder' => ':html', 'value' => $draft->html, 'type' => ParameterType::STRING],
            ['column' => 'fields_json', 'placeholder' => ':fields', 'value' => $fieldsJson, 'type' => ParameterType::STRING],
            ['column' => 'tags_json', 'placeholder' => ':tags', 'value' => $tagsJson, 'type' => ParameterType::STRING],
            ['column' => 'images_json', 'placeholder' => ':images', 'value' => $imagesJson, 'type' => ParameterType::STRING],
            ['column' => 'metadesc', 'placeholder' => ':metadesc', 'value' => $draft->metadesc, 'type' => ParameterType::STRING],
            ['column' => 'metakey', 'placeholder' => ':metakey', 'value' => $draft->metakey, 'type' => ParameterType::STRING],
        ];
    }
}
