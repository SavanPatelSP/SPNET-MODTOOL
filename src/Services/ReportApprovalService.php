<?php

namespace App\Services;

use App\Database;

class ReportApprovalService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function getStatus(int|string $chatId, string $month, string $type = 'reward'): array
    {
        $row = $this->db->fetch(
            'SELECT status, approved_by, approved_at FROM report_approvals WHERE chat_id = ? AND month = ? AND report_type = ?',
            [$chatId, $month, $type]
        );
        if ($row) {
            return $row;
        }
        return [
            'status' => 'pending',
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function approve(int|string $chatId, string $month, int|string $userId, string $type = 'reward'): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->exec(
            'INSERT INTO report_approvals (chat_id, month, report_type, status, approved_by, approved_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), approved_by = VALUES(approved_by), approved_at = VALUES(approved_at), updated_at = VALUES(updated_at)',
            [$chatId, $month, $type, 'approved', $userId, $now, $now, $now]
        );
    }
}
