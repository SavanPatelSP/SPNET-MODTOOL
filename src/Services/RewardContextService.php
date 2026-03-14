<?php

namespace App\Services;

use App\Database;

class RewardContextService
{
    private SubscriptionService $subscriptions;
    private RewardHistoryService $history;
    private array $config;

    public function __construct(Database $db, array $config)
    {
        $this->subscriptions = new SubscriptionService($db, $config);
        $this->history = new RewardHistoryService($db);
        $this->config = $config;
    }

    public function build(int|string $chatId, ?string $month): array
    {
        $premium = $this->subscriptions->isPremium($chatId);
        $context = [
            'premium' => $premium,
        ];

        if (!$premium) {
            return $context;
        }

        $rewardConfig = $this->config['premium']['reward'] ?? [];
        $context['max_share'] = $rewardConfig['max_share'] ?? null;

        $months = (int)($rewardConfig['stability_months'] ?? 0);
        $bonus = (float)($rewardConfig['stability_bonus'] ?? 0);
        if ($month && $months > 0 && $bonus > 0) {
            $topN = (int)($this->config['reward']['top_n'] ?? 5);
            $context['stability_bonus'] = $this->history->getStabilityBonusMap($chatId, $month, $months, $bonus, $topN);
        }

        return $context;
    }
}
