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

        $eligibility = $this->config['eligibility'] ?? [];
        $minDays = (int)($eligibility['min_days_active'] ?? 0);
        $minMessages = (int)($eligibility['min_messages'] ?? 0);
        $minScore = (float)($eligibility['min_score'] ?? 0);

        $topN = $this->config['reward']['top_n'] ?? count($mods);
        $topN = min($topN, count($mods));
        $rankMultipliers = $this->config['reward']['rank_multipliers'] ?? [];
        $minReward = (float)($this->config['reward']['min_reward'] ?? 0);

        $eligible = [];
        foreach ($mods as $mod) {
            $isEligible = true;
            if ($minDays > 0 && ($mod['days_active'] ?? 0) < $minDays) {
                $isEligible = false;
            }
            if ($minMessages > 0 && ($mod['messages'] ?? 0) < $minMessages) {
                $isEligible = false;
            }
            if ($minScore > 0 && ($mod['score'] ?? 0) < $minScore) {
                $isEligible = false;
            }
            $mod['eligible'] = $isEligible;
            $eligible[] = $mod;
        }

        $eligibleTop = [];
        foreach ($eligible as $mod) {
            if ($mod['eligible']) {
                $eligibleTop[] = $mod;
                if (count($eligibleTop) >= $topN) {
                    break;
                }
            }
        }

        $adjustedScores = [];
        $total = 0.0;
        $rank = 1;
        foreach ($eligibleTop as $mod) {
            $multiplier = $rankMultipliers[$rank] ?? 1.0;
            $adjusted = $mod['score'] * $multiplier;
            $adjustedScores[] = $adjusted;
            $total += $adjusted;
            $rank++;
        }

        $rewards = [];
        $remainingBudget = $budget;
        foreach ($eligibleTop as $i => $mod) {
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

        $rewardedMap = [];
        foreach ($rewards as $mod) {
            $rewardedMap[$mod['user_id']] = $mod;
        }

        $final = [];
        foreach ($eligible as $mod) {
            if (isset($rewardedMap[$mod['user_id']])) {
                $final[] = $rewardedMap[$mod['user_id']];
            } else {
                $mod['reward'] = 0.0;
                $final[] = $mod;
            }
        }

        return $final;
    }
}
