<?php

namespace App\Services;

use App\Database;

class AuditLogService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function log(string $action, int|string $actorId, ?int $chatId = null, array $meta = []): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $payload = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
        $this->db->exec(
            'INSERT INTO audit_log (action, actor_id, chat_id, meta, created_at) VALUES (?, ?, ?, ?, ?)',
            [$action, $actorId, $chatId, $payload, $now]
        );
    }

    public function list(int|string $chatId = null, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        if ($chatId !== null) {
            return $this->db->fetchAll(
                'SELECT action, actor_id, chat_id, meta, created_at FROM audit_log WHERE chat_id = ? ORDER BY created_at DESC LIMIT ' . $limit,
                [$chatId]
            );
        }
        return $this->db->fetchAll(
            'SELECT action, actor_id, chat_id, meta, created_at FROM audit_log ORDER BY created_at DESC LIMIT ' . $limit
        );
    }
}
