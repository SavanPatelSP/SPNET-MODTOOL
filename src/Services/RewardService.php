<?php

namespace App\Services;

class RewardService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function rankAndReward(array $mods, float $budget): array
    {
        usort($mods, function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        $topN = $this->config['reward']['top_n'] ?? count($mods);
        $topN = min($topN, count($mods));
        $rankMultipliers = $this->config['reward']['rank_multipliers'] ?? [];
        $minReward = (float)($this->config['reward']['min_reward'] ?? 0);

        $eligible = array_slice($mods, 0, $topN);
        $adjustedScores = [];
        $total = 0.0;
        $rank = 1;
        foreach ($eligible as $mod) {
            $multiplier = $rankMultipliers[$rank] ?? 1.0;
            $adjusted = $mod['score'] * $multiplier;
            $adjustedScores[] = $adjusted;
            $total += $adjusted;
            $rank++;
        }

        $rewards = [];
        $remainingBudget = $budget;
        foreach ($eligible as $i => $mod) {
            $reward = 0.0;
            if ($total > 0) {
                $reward = ($budget * $adjustedScores[$i]) / $total;
                $reward = max($minReward, $reward);
            }
            $reward = round($reward, 2);
            $remainingBudget -= $reward;
            $mod['reward'] = $reward;
            $rewards[] = $mod;
        }

        if (!empty($rewards)) {
            $rewards[count($rewards) - 1]['reward'] = round($rewards[count($rewards) - 1]['reward'] + $remainingBudget, 2);
        }

        return $rewards;
    }
}
