<?php

namespace App\Services;

use App\Database;

class UserSettingsService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function getDefaultChatId(int|string $userId): ?int
    {
        $row = $this->db->fetch('SELECT default_chat_id FROM user_settings WHERE user_id = ?', [$userId]);
        if (!$row) {
            return null;
        }
        $value = $row['default_chat_id'];
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }

    public function setDefaultChatId(int|string $userId, int|string $chatId): void
    {
        $this->db->exec(
            'INSERT INTO user_settings (user_id, default_chat_id, updated_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE default_chat_id = VALUES(default_chat_id), updated_at = VALUES(updated_at)',
            [$userId, $chatId, $this->nowUtc()]
        );
    }

    public function clearDefaultChatId(int|string $userId): void
    {
        $this->db->exec(
            'INSERT INTO user_settings (user_id, default_chat_id, updated_at)
             VALUES (?, NULL, ?)
             ON DUPLICATE KEY UPDATE default_chat_id = VALUES(default_chat_id), updated_at = VALUES(updated_at)',
            [$userId, $this->nowUtc()]
        );
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
