<?php

namespace App\Services;

use App\Database;

class PaymentService
{
    private Database $db;
    private array $config;

    public function __construct(Database $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function isTestMode(): bool
    {
        $payments = $this->config['payments'] ?? [];
        return !empty($payments['test_mode']);
    }

    public function selectTier(string $method, float $amount): ?array
    {
        $payments = $this->config['payments'] ?? [];
        $tiersKey = $method === 'crypto' ? 'crypto_tiers' : 'stars_tiers';
        $tiers = $payments[$tiersKey] ?? [];
        if (!is_array($tiers) || empty($tiers)) {
            return null;
        }
        $selected = null;
        foreach ($tiers as $tier) {
            $min = isset($tier['min']) ? (float)$tier['min'] : null;
            if ($min === null) {
                continue;
            }
            if ($amount >= $min && ($selected === null || $min > (float)($selected['min'] ?? 0))) {
                $selected = $tier;
            }
        }
        return $selected;
    }

    public function recordTest(int|string $chatId, int|string $userId, string $method, float $amount, string $currency, ?string $plan, ?int $days, array $meta = []): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $payload = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;
        $this->db->exec(
            'INSERT INTO payments (chat_id, user_id, method, amount, currency, status, plan, days, meta, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int)$chatId, (int)$userId, $method, $amount, $currency, 'test', $plan, $days, $payload, $now]
        );
    }

    public function latestForUser(int|string $userId): ?array
    {
        $row = $this->db->fetch(
            'SELECT method, amount, currency, status, plan, days, created_at, chat_id FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 1',
            [(int)$userId]
        );
        return $row ?: null;
    }
}
