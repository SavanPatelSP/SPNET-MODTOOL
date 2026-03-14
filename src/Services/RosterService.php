<?php

namespace App\Services;

use App\Database;

class RosterService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function setRole(int|string $chatId, int|string $userId, string $role, ?string $notes = null): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->exec(
            'INSERT INTO mod_roster (chat_id, user_id, role, notes, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), notes = VALUES(notes), updated_at = VALUES(updated_at)',
            [$chatId, $userId, $role, $notes, $now]
        );
    }

    public function remove(int|string $chatId, int|string $userId): void
    {
        $this->db->exec('DELETE FROM mod_roster WHERE chat_id = ? AND user_id = ?', [$chatId, $userId]);
    }

    public function list(int|string $chatId): array
    {
        return $this->db->fetchAll(
            'SELECT mr.user_id, mr.role, mr.notes, mr.updated_at, u.username, u.first_name, u.last_name
             FROM mod_roster mr
             JOIN users u ON u.id = mr.user_id
             WHERE mr.chat_id = ?
             ORDER BY mr.role ASC, u.first_name ASC',
            [$chatId]
        );
    }
}
