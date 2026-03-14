<?php

namespace App\Services;

use App\Database;

class SubscriptionService
{
    private Database $db;
    private array $config;

    public function __construct(Database $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function get(int|string $chatId): array
    {
        $row = $this->db->fetch('SELECT * FROM subscriptions WHERE chat_id = ?', [$chatId]);
        if ($row) {
            return $row;
        }

        $plan = $this->config['premium']['default_plan'] ?? 'free';
        $now = $this->nowUtc();
        $this->db->exec(
            'INSERT INTO subscriptions (chat_id, plan, status, started_at, expires_at, updated_at)
             VALUES (?, ?, ?, ?, NULL, ?)',
            [$chatId, $plan, 'active', $now, $now]
        );

        return [
            'chat_id' => $chatId,
            'plan' => $plan,
            'status' => 'active',
            'started_at' => $now,
            'expires_at' => null,
            'updated_at' => $now,
        ];
    }

    public function isPremium(int|string $chatId): bool
    {
        $enabled = $this->config['premium']['enabled'] ?? true;
        if (!$enabled) {
            return true;
        }

        $sub = $this->get($chatId);
        $plan = strtolower((string)($sub['plan'] ?? 'free'));
        if (!in_array($plan, ['premium', 'enterprise'], true)) {
            return false;
        }
        if (($sub['status'] ?? 'active') !== 'active') {
            return false;
        }
        if (!empty($sub['expires_at']) && strtotime($sub['expires_at']) < time()) {
            $this->db->exec('UPDATE subscriptions SET status = ?, updated_at = ? WHERE chat_id = ?', ['expired', $this->nowUtc(), $chatId]);
            return false;
        }
        return true;
    }

    public function setPlan(int|string $chatId, string $plan, ?int $days = null): array
    {
        $plan = strtolower(trim($plan));
        if (!in_array($plan, ['free', 'premium', 'enterprise'], true)) {
            $plan = 'free';
        }

        $now = $this->nowUtc();
        $expires = null;
        if ($days !== null && $days > 0) {
            $expires = gmdate('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
        }

        $this->db->exec(
            'INSERT INTO subscriptions (chat_id, plan, status, started_at, expires_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE plan = VALUES(plan), status = VALUES(status), started_at = VALUES(started_at), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)',
            [$chatId, $plan, 'active', $now, $expires, $now]
        );

        return $this->get($chatId);
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
