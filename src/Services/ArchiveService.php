<?php

namespace App\Services;

use App\Database;

class ArchiveService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function record(?int $chatId, string $type, string $month, string $filePath): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->exec(
            'INSERT INTO report_archive (chat_id, report_type, month, file_path, created_at)
             VALUES (?, ?, ?, ?, ?)',
            [$chatId, $type, $month, $filePath, $now]
        );
    }

    public function list(?int $chatId, ?string $type = null, int $limit = 10): array
    {
        $params = [];
        $where = [];
        if ($chatId !== null) {
            $where[] = 'chat_id = ?';
            $params[] = $chatId;
        }
        if ($type !== null) {
            $where[] = 'report_type = ?';
            $params[] = $type;
        }
        $sql = 'SELECT * FROM report_archive';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ' . (int)$limit;

        return $this->db->fetchAll($sql, $params);
    }
}
