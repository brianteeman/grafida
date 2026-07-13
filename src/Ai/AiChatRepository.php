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
 * Data-access for AI conversations and their message transcripts.
 */
final class AiChatRepository
{
    use QueryBuilderSupport;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Returns all chats for a draft (metadata only; messages are not loaded).
     *
     * @return list<AiChat>
     */
    public function forDraft(int $draftId): array
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('ai_chats'))
            ->where($this->qn('draft_id') . ' = :draft')
            ->order($this->qn('updated_at') . ' DESC')
            ->bind(':draft', $draftId, ParameterType::INTEGER);

        /** @var list<array{id?: int|string|null, draft_id: int|string, service_id: int|string|null, title: string}> $rows */
        $rows = $this->db->setQuery($query)->loadAssocList();

        return array_values(array_map(static fn (array $r): AiChat => AiChat::fromRow($r), $rows));
    }

    /**
     * Returns a single chat with its messages ordered by sort_order, or null when not found.
     */
    public function find(int $id): ?AiChat
    {
        $chatQuery = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('ai_chats'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        /** @var array{id?: int|string|null, draft_id: int|string, service_id: int|string|null, title: string}|null $row */
        $row = $this->db->setQuery($chatQuery)->loadAssoc();

        if ($row === null) {
            return null;
        }

        $msgQuery = $this->db->createQuery()
            ->select('*')
            ->from($this->qn('ai_chat_messages'))
            ->where($this->qn('chat_id') . ' = :chat')
            ->order($this->qn('sort_order') . ' ASC')
            ->bind(':chat', $id, ParameterType::INTEGER);

        /** @var list<array{id?: int|string|null, chat_id: int|string|null, role: string, content: string, tool_key: string|null, sort_order: int|string}> $msgRows */
        $msgRows  = $this->db->setQuery($msgQuery)->loadAssocList();
        $messages = array_values(array_map(static fn (array $r): AiMessage => AiMessage::fromRow($r), $msgRows));

        return AiChat::fromRow($row, $messages);
    }

    /**
     * Persists a new chat together with its initial messages in one transaction.
     *
     * Returns the new chat's id.
     */
    public function create(AiChat $chat): int
    {
        $now = gmdate('Y-m-d H:i:s');

        $draftId            = $chat->draftId;
        $serviceId          = $chat->serviceId;
        $title              = $chat->title;
        $previousResponseId = $chat->previousResponseId;
        $lastResponseAt     = $chat->lastResponseAt;

        $this->db->transactionStart();

        try {
            $chatQuery = $this->db->createQuery()
                ->insert($this->qn('ai_chats'))
                ->columns([
                    $this->qn('draft_id'),
                    $this->qn('service_id'),
                    $this->qn('title'),
                    $this->qn('created_at'),
                    $this->qn('updated_at'),
                    $this->qn('previous_response_id'),
                    $this->qn('last_response_at'),
                ])
                ->values(':draft_id, :service_id, :title, :created_at, :updated_at, :previous_response_id, :last_response_at')
                ->bind(':draft_id', $draftId, ParameterType::INTEGER)
                ->bind(':service_id', $serviceId, $serviceId === null ? ParameterType::NULL : ParameterType::INTEGER)
                ->bind(':title', $title, ParameterType::STRING)
                ->bind(':created_at', $now, ParameterType::STRING)
                ->bind(':updated_at', $now, ParameterType::STRING)
                ->bind(':previous_response_id', $previousResponseId, $previousResponseId === null ? ParameterType::NULL : ParameterType::STRING)
                ->bind(':last_response_at', $lastResponseAt, $lastResponseAt === null ? ParameterType::NULL : ParameterType::STRING);

            $this->db->setQuery($chatQuery)->execute();

            $chatId = $this->lastInsertId();

            // Bind a throwaway copy, not $chatId itself: bind() takes its value by
            // reference (typed `mixed`), which would otherwise widen $chatId's type
            // for the rest of this method — including the `return $chatId;` below.
            $chatIdForBind = $chatId;

            foreach ($chat->messages as $message) {
                $role      = $message->role;
                $content   = $message->content;
                $toolKey   = $message->toolKey;
                $sortOrder = $message->sortOrder;

                $msgQuery = $this->db->createQuery()
                    ->insert($this->qn('ai_chat_messages'))
                    ->columns([
                        $this->qn('chat_id'),
                        $this->qn('role'),
                        $this->qn('content'),
                        $this->qn('tool_key'),
                        $this->qn('sort_order'),
                        $this->qn('created_at'),
                    ])
                    ->values(':chat_id, :role, :content, :tool_key, :sort_order, :created_at')
                    ->bind(':chat_id', $chatIdForBind, ParameterType::INTEGER)
                    ->bind(':role', $role, ParameterType::STRING)
                    ->bind(':content', $content, ParameterType::STRING)
                    ->bind(':tool_key', $toolKey, $toolKey === null ? ParameterType::NULL : ParameterType::STRING)
                    ->bind(':sort_order', $sortOrder, ParameterType::INTEGER)
                    ->bind(':created_at', $now, ParameterType::STRING);

                $this->db->setQuery($msgQuery)->execute();
            }

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            throw $e;
        }

        return $chatId;
    }

    /** Updates the title of a chat. */
    public function rename(int $id, string $title): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $query = $this->db->createQuery()
            ->update($this->qn('ai_chats'))
            ->set($this->qn('title') . ' = :title')
            ->set($this->qn('updated_at') . ' = :now')
            ->where($this->qn('id') . ' = :id')
            ->bind(':title', $title, ParameterType::STRING)
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Updates the response-id chain (and its owning service) in one statement, so a
     * chain is never left pointing at a different service than the one that produced it.
     */
    public function setResponseChain(int $id, ?int $serviceId, ?string $responseId, ?string $lastResponseAt): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $query = $this->db->createQuery()
            ->update($this->qn('ai_chats'))
            ->set($this->qn('service_id') . ' = :service_id')
            ->set($this->qn('previous_response_id') . ' = :response_id')
            ->set($this->qn('last_response_at') . ' = :last_response_at')
            ->set($this->qn('updated_at') . ' = :now')
            ->where($this->qn('id') . ' = :id')
            ->bind(':service_id', $serviceId, $serviceId === null ? ParameterType::NULL : ParameterType::INTEGER)
            ->bind(':response_id', $responseId, $responseId === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':last_response_at', $lastResponseAt, $lastResponseAt === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /** Deletes a chat and, via ON DELETE CASCADE, all its messages. */
    public function delete(int $id): void
    {
        $query = $this->db->createQuery()
            ->delete($this->qn('ai_chats'))
            ->where($this->qn('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Replaces all messages for a chat in one transaction.
     *
     * Deletes every existing message for the chat and inserts the supplied list.
     *
     * @param list<AiMessage> $messages
     */
    public function replaceMessages(int $chatId, array $messages): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->db->transactionStart();

        try {
            $delQuery = $this->db->createQuery()
                ->delete($this->qn('ai_chat_messages'))
                ->where($this->qn('chat_id') . ' = :chat')
                ->bind(':chat', $chatId, ParameterType::INTEGER);

            $this->db->setQuery($delQuery)->execute();

            foreach ($messages as $message) {
                $role      = $message->role;
                $content   = $message->content;
                $toolKey   = $message->toolKey;
                $sortOrder = $message->sortOrder;

                $insQuery = $this->db->createQuery()
                    ->insert($this->qn('ai_chat_messages'))
                    ->columns([
                        $this->qn('chat_id'),
                        $this->qn('role'),
                        $this->qn('content'),
                        $this->qn('tool_key'),
                        $this->qn('sort_order'),
                        $this->qn('created_at'),
                    ])
                    ->values(':chat_id, :role, :content, :tool_key, :sort_order, :created_at')
                    ->bind(':chat_id', $chatId, ParameterType::INTEGER)
                    ->bind(':role', $role, ParameterType::STRING)
                    ->bind(':content', $content, ParameterType::STRING)
                    ->bind(':tool_key', $toolKey, $toolKey === null ? ParameterType::NULL : ParameterType::STRING)
                    ->bind(':sort_order', $sortOrder, ParameterType::INTEGER)
                    ->bind(':created_at', $now, ParameterType::STRING);

                $this->db->setQuery($insQuery)->execute();
            }

            $updQuery = $this->db->createQuery()
                ->update($this->qn('ai_chats'))
                ->set($this->qn('updated_at') . ' = :now')
                ->where($this->qn('id') . ' = :id')
                ->bind(':now', $now, ParameterType::STRING)
                ->bind(':id', $chatId, ParameterType::INTEGER);

            $this->db->setQuery($updQuery)->execute();

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            throw $e;
        }
    }
}
