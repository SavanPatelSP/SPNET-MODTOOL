<?php

namespace App\Services;

use App\Database;

class NotificationService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function wasSent(int|string $chatId, string $type, ?string $period = null): bool
    {
        $row = $this->db->fetch(
            'SELECT id FROM notification_log WHERE chat_id = ? AND notification_type = ? AND period <=> ? LIMIT 1',
            [$chatId, $type, $period]
        );
        return (bool)$row;
    }

    public function markSent(int|string $chatId, string $type, ?string $period = null): void
    {
        $this->db->exec(
            'INSERT INTO notification_log (chat_id, notification_type, period, sent_at)
             VALUES (?, ?, ?, ?)',
            [$chatId, $type, $period, gmdate('Y-m-d H:i:s')]
        );
    }
}
