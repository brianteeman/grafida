<?php

/**
 * Grafida — edit Joomla! articles on your desktop.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Ai;

use Grafida\Storage\QueryBuilderSupport;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Data-access for AI tool overrides and custom tools.
 */
final class AiToolRepository
{
    use QueryBuilderSupport;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /** @return list<AiTool> */
    public function all(): array
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('ai_tools'))
            ->order($this->qn('sort_order') . ' ASC, ' . $this->qn('id') . ' ASC');

        /** @var list<array{id?: int|string|null, tool_key: string, title: string, icon: string, prompt: string, override_system: int|string, tone: string, params_json: string, service_id: int|string|null, is_custom: int|string, enabled: int|string, sort_order: int|string}> $rows */
        $rows = $this->db->setQuery($query)->loadAssocList();

        return array_values(array_map(static fn (array $r): AiTool => AiTool::fromRow($r), $rows));
    }

    public function findByKey(string $key): ?AiTool
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('ai_tools'))
            ->where($this->qn('tool_key') . ' = :key')
            ->bind(':key', $key, ParameterType::STRING);

        /** @var array{id?: int|string|null, tool_key: string, title: string, icon: string, prompt: string, override_system: int|string, tone: string, params_json: string, service_id: int|string|null, is_custom: int|string, enabled: int|string, sort_order: int|string}|null $row */
        $row = $this->db->setQuery($query)->loadAssoc();

        return $row !== null ? AiTool::fromRow($row) : null;
    }

    /**
     * Inserts a new tool or replaces an existing one matched by tool_key.
     *
     * Returns the tool's id (existing id on update, new id on insert).
     *
     * Wrapped in a transaction: this is a read-then-write (find, then insert
     * or update), which must not race with a concurrent writer.
     */
    public function upsert(AiTool $tool): int
    {
        $this->db->transactionStart();

        try {
            $now      = gmdate('Y-m-d H:i:s');
            $toolKey  = $tool->toolKey;
            $existing = $this->findByKey($toolKey);
            $cols     = $this->columns($tool);

            if ($existing === null) {
                $query = $this->db->createQuery()
                    ->insert($this->qn('ai_tools'))
                    ->columns([
                        $this->qn('tool_key'),
                        ...array_map(fn (array $c): string => $this->qn($c['column']), $cols),
                        $this->qn('created_at'),
                        $this->qn('updated_at'),
                    ])
                    ->values(
                        ':tool_key, '
                        . implode(', ', array_map(static fn (array $c): string => $c['placeholder'], $cols))
                        . ', :created_at, :updated_at'
                    )
                    ->bind(':tool_key', $toolKey, ParameterType::STRING)
                    ->bind(':created_at', $now, ParameterType::STRING)
                    ->bind(':updated_at', $now, ParameterType::STRING);

                foreach ($cols as $i => $c) {
                    $query->bind($c['placeholder'], $cols[$i]['value'], $c['type']);
                }

                $this->db->setQuery($query)->execute();

                $id = $this->lastInsertId();
            } else {
                $query = $this->db->createQuery()->update($this->qn('ai_tools'));

                foreach ($cols as $c) {
                    $query->set($this->qn($c['column']) . ' = ' . $c['placeholder']);
                }

                $query->set($this->qn('updated_at') . ' = :now')
                    ->where($this->qn('tool_key') . ' = :tool_key')
                    ->bind(':now', $now, ParameterType::STRING)
                    ->bind(':tool_key', $toolKey, ParameterType::STRING);

                foreach ($cols as $i => $c) {
                    $query->bind($c['placeholder'], $cols[$i]['value'], $c['type']);
                }

                $this->db->setQuery($query)->execute();

                $id = (int) $existing->id;
            }

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            throw $e;
        }

        return $id;
    }

    public function delete(string $key): void
    {
        $query = $this->db->createQuery()
            ->delete($this->qn('ai_tools'))
            ->where($this->qn('tool_key') . ' = :key')
            ->bind(':key', $key, ParameterType::STRING);

        $this->db->setQuery($query)->execute();
    }

    /**
     * @return list<array{column: string, placeholder: string, value: mixed, type: string}>
     */
    private function columns(AiTool $tool): array
    {
        $paramsJson = json_encode($tool->params, \JSON_UNESCAPED_UNICODE);

        return [
            ['column' => 'title', 'placeholder' => ':title', 'value' => $tool->title, 'type' => ParameterType::STRING],
            ['column' => 'icon', 'placeholder' => ':icon', 'value' => $tool->icon, 'type' => ParameterType::STRING],
            ['column' => 'prompt', 'placeholder' => ':prompt', 'value' => $tool->prompt, 'type' => ParameterType::STRING],
            ['column' => 'override_system', 'placeholder' => ':override_system', 'value' => $tool->overrideSystem ? 1 : 0, 'type' => ParameterType::INTEGER],
            ['column' => 'tone', 'placeholder' => ':tone', 'value' => $tool->tone, 'type' => ParameterType::STRING],
            ['column' => 'params_json', 'placeholder' => ':params', 'value' => $paramsJson, 'type' => ParameterType::STRING],
            ['column' => 'service_id', 'placeholder' => ':service_id', 'value' => $tool->serviceId, 'type' => $tool->serviceId === null ? ParameterType::NULL : ParameterType::INTEGER],
            ['column' => 'is_custom', 'placeholder' => ':is_custom', 'value' => $tool->isCustom ? 1 : 0, 'type' => ParameterType::INTEGER],
            ['column' => 'enabled', 'placeholder' => ':enabled', 'value' => $tool->enabled ? 1 : 0, 'type' => ParameterType::INTEGER],
            ['column' => 'sort_order', 'placeholder' => ':sort_order', 'value' => $tool->sortOrder, 'type' => ParameterType::INTEGER],
        ];
    }
}
