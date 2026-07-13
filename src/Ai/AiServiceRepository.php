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
 * Data-access for AI service configurations.
 */
final class AiServiceRepository
{
    use QueryBuilderSupport;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /** @return list<AiService> */
    public function all(): array
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('ai_services'))
            ->order($this->qn('id') . ' ASC');

        /** @var list<array{id?: int|string|null, name: string, provider: string, endpoint: string, model: string, params_json: string, secret_ref: string|null, insecure_key: string|null, is_default: int|string}> $rows */
        $rows = $this->db->setQuery($query)->loadAssocList();

        return array_values(array_map(static fn (array $r): AiService => AiService::fromRow($r), $rows));
    }

    public function find(int $id): ?AiService
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('ai_services'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        /** @var array{id?: int|string|null, name: string, provider: string, endpoint: string, model: string, params_json: string, secret_ref: string|null, insecure_key: string|null, is_default: int|string}|null $row */
        $row = $this->db->setQuery($query)->loadAssoc();

        return $row !== null ? AiService::fromRow($row) : null;
    }

    /** Inserts a new AI service and returns its id. */
    public function insert(AiService $service): int
    {
        $now  = gmdate('Y-m-d H:i:s');
        $cols = $this->columns($service);

        $query = $this->db->createQuery()
            ->insert($this->qn('ai_services'))
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

    public function update(AiService $service): void
    {
        if ($service->id === null) {
            throw new \InvalidArgumentException('Cannot update an AI service without an id.');
        }

        $now  = gmdate('Y-m-d H:i:s');
        $id   = $service->id;
        $cols = $this->columns($service);

        $query = $this->db->createQuery()->update($this->qn('ai_services'));

        foreach ($cols as $c) {
            $query->set($this->qn($c['column']) . ' = ' . $c['placeholder']);
        }

        $query->set($this->qn('updated_at') . ' = :now')
            ->where($this->qn('id') . ' = :id')
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        foreach ($cols as $i => $c) {
            $query->bind($c['placeholder'], $cols[$i]['value'], $c['type']);
        }

        $this->db->setQuery($query)->execute();
    }

    public function delete(int $id): void
    {
        $query = $this->db->createQuery()
            ->delete($this->qn('ai_services'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /** Sets is_default = 0 for every AI service. */
    public function clearDefault(): void
    {
        $query = $this->db->createQuery()
            ->update($this->qn('ai_services'))
            ->set($this->qn('is_default') . ' = 0');

        $this->db->setQuery($query)->execute();
    }

    /**
     * Marks the given service as the sole default (clears all others first).
     *
     * Wrapped in a transaction: a crash between clearing and setting would
     * otherwise leave the app with zero default services.
     */
    public function setDefault(int $id): void
    {
        $this->db->transactionStart();

        try {
            $this->clearDefault();

            $query = $this->db->createQuery()
                ->update($this->qn('ai_services'))
                ->set($this->qn('is_default') . ' = 1')
                ->where($this->qn('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);

            $this->db->setQuery($query)->execute();

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            throw $e;
        }
    }

    /**
     * @return list<array{column: string, placeholder: string, value: mixed, type: string}>
     */
    private function columns(AiService $service): array
    {
        $paramsJson = json_encode($service->params, \JSON_UNESCAPED_UNICODE);

        return [
            ['column' => 'name', 'placeholder' => ':name', 'value' => $service->name, 'type' => ParameterType::STRING],
            ['column' => 'provider', 'placeholder' => ':provider', 'value' => $service->provider, 'type' => ParameterType::STRING],
            ['column' => 'endpoint', 'placeholder' => ':endpoint', 'value' => $service->endpoint, 'type' => ParameterType::STRING],
            ['column' => 'model', 'placeholder' => ':model', 'value' => $service->model, 'type' => ParameterType::STRING],
            ['column' => 'params_json', 'placeholder' => ':params', 'value' => $paramsJson, 'type' => ParameterType::STRING],
            ['column' => 'secret_ref', 'placeholder' => ':secret_ref', 'value' => $service->secretRef, 'type' => $service->secretRef === null ? ParameterType::NULL : ParameterType::STRING],
            ['column' => 'insecure_key', 'placeholder' => ':insecure_key', 'value' => $service->insecureKey, 'type' => $service->insecureKey === null ? ParameterType::NULL : ParameterType::STRING],
            ['column' => 'is_default', 'placeholder' => ':is_default', 'value' => $service->isDefault ? 1 : 0, 'type' => ParameterType::INTEGER],
        ];
    }
}
