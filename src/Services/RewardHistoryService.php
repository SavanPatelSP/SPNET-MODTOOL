<?php

namespace App\Services;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;

class RewardHistoryService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function record(int|string $chatId, string $month, array $ranked): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $rank = 1;
        foreach ($ranked as $mod) {
            $this->db->exec(
                'INSERT INTO reward_history (chat_id, month, user_id, rank, score, reward, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE rank = VALUES(rank), score = VALUES(score), reward = VALUES(reward), created_at = VALUES(created_at)',
                [
                    $chatId,
                    $month,
                    $mod['user_id'],
                    $rank,
                    (float)($mod['score'] ?? 0),
                    (float)($mod['reward'] ?? 0),
                    $now,
                ]
            );
            $rank++;
        }
    }

    public function getStabilityBonusMap(int|string $chatId, string $month, int $months, float $bonus, int $topN = 5): array
    {
        if ($months <= 1 || $bonus <= 0) {
            return [];
        }

        $targetMonths = $this->previousMonths($month, $months);
        $placeholders = implode(',', array_fill(0, count($targetMonths), '?'));
        $rows = $this->db->fetchAll(
            'SELECT user_id, COUNT(*) as cnt FROM reward_history WHERE chat_id = ? AND month IN (' . $placeholders . ') AND rank <= ? GROUP BY user_id',
            array_merge([$chatId], $targetMonths, [$topN])
        );

        $map = [];
        foreach ($rows as $row) {
            if ((int)$row['cnt'] >= $months) {
                $map[(int)$row['user_id']] = $bonus;
            }
        }
        return $map;
    }

    private function previousMonths(string $month, int $count): array
    {
        $tz = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00', $tz);
        if ($start === false) {
            $start = new DateTimeImmutable('first day of this month 00:00:00', $tz);
        }
        $months = [];
        for ($i = 1; $i <= $count; $i++) {
            $months[] = $start->modify('-' . $i . ' month')->format('Y-m');
        }
        return $months;
    }
}
