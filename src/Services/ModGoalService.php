<?php

namespace App\Services;

use App\Database;

class ModGoalService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function getGoal(int|string $chatId, int|string $userId, string $month): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM mod_goals WHERE chat_id = ? AND user_id = ? AND month = ?',
            [(int)$chatId, (int)$userId, $month]
        );
        return $row ?: null;
    }

    public function setGoal(int|string $chatId, int|string $userId, string $month, string $field, float $value): void
    {
        $columns = [
            'messages' => 'messages_target',
            'active_hours' => 'active_hours_target',
            'actions' => 'actions_target',
            'days_active' => 'days_active_target',
            'score' => 'score_target',
        ];
        if (!isset($columns[$field])) {
            return;
        }

        $column = $columns[$field];
        $now = gmdate('Y-m-d H:i:s');

        $existing = $this->getGoal($chatId, $userId, $month);
        if ($existing) {
            $this->db->exec(
                'UPDATE mod_goals SET ' . $column . ' = ?, updated_at = ? WHERE chat_id = ? AND user_id = ? AND month = ?',
                [$value, $now, (int)$chatId, (int)$userId, $month]
            );
            return;
        }

        $defaults = [
            'messages_target' => null,
            'active_hours_target' => null,
            'actions_target' => null,
            'days_active_target' => null,
            'score_target' => null,
        ];
        $defaults[$column] = $value;

        $this->db->exec(
            'INSERT INTO mod_goals (chat_id, user_id, month, messages_target, active_hours_target, actions_target, days_active_target, score_target, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int)$chatId,
                (int)$userId,
                $month,
                $defaults['messages_target'],
                $defaults['active_hours_target'],
                $defaults['actions_target'],
                $defaults['days_active_target'],
                $defaults['score_target'],
                $now,
                $now,
            ]
        );
    }

    public function clearGoals(int|string $chatId, int|string $userId, string $month): void
    {
        $this->db->exec(
            'DELETE FROM mod_goals WHERE chat_id = ? AND user_id = ? AND month = ?',
            [(int)$chatId, (int)$userId, $month]
        );
    }
}
