<?php

namespace App\Services;

class RewardService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function rankAndReward(array $mods, float $budget, array $context = []): array
    {
        usort($mods, function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        $eligibility = $this->config['eligibility'] ?? [];
        $minDays = (int)($eligibility['min_days_active'] ?? 0);
        $minMessages = (int)($eligibility['min_messages'] ?? 0);
        $minScore = (float)($eligibility['min_score'] ?? 0);
        $minActions = (int)($eligibility['min_actions'] ?? 0);
        $minActiveHours = (float)($eligibility['min_active_hours'] ?? 0);

        $topN = $this->config['reward']['top_n'] ?? count($mods);
        $topN = min($topN, count($mods));
        $rankMultipliers = $this->config['reward']['rank_multipliers'] ?? [];
        $minReward = (float)($this->config['reward']['min_reward'] ?? 0);
        $bonusPercent = (float)($this->config['reward']['kpi_bonus_percent'] ?? 0);
        $bonusSplit = $this->config['reward']['kpi_bonus_split'] ?? [];
        $bonusPool = $budget > 0 ? max(0.0, $budget * $bonusPercent) : 0.0;
        $baseBudget = max(0.0, $budget - $bonusPool);

        $premium = (bool)($context['premium'] ?? false);
        $premiumReward = $this->config['premium']['reward'] ?? [];
        $stabilityBonus = $context['stability_bonus'] ?? [];
        $penaltyWeight = (float)($premiumReward['penalty_weight'] ?? 0);
        $penaltyDecay = (float)($premiumReward['penalty_decay'] ?? 0.5);

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
            if ($minActions > 0) {
                $actions = (int)(($mod['warnings'] ?? 0) + ($mod['mutes'] ?? 0) + ($mod['bans'] ?? 0));
                if ($actions < $minActions) {
                    $isEligible = false;
                }
            }
            if ($minActiveHours > 0) {
                $hours = ((float)($mod['active_minutes'] ?? 0)) / 60;
                if ($hours < $minActiveHours) {
                    $isEligible = false;
                }
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

        $bonusMap = $this->buildBonusMap($eligible, $bonusPool, $bonusSplit);

        $adjustedScores = [];
        $total = 0.0;
        $rank = 1;
        foreach ($eligibleTop as $mod) {
            $multiplier = $rankMultipliers[$rank] ?? 1.0;
            $adjusted = $mod['score'] * $multiplier;
            if ($premium && isset($stabilityBonus[$mod['user_id']])) {
                $adjusted *= (1 + (float)$stabilityBonus[$mod['user_id']]);
            }
            if ($premium && $penaltyWeight > 0 && ($mod['improvement'] ?? 0) < 0) {
                $penalty = min($penaltyWeight, (abs($mod['improvement']) / 100) * $penaltyWeight);
                $adjusted *= max(0.0, 1 - ($penalty * $penaltyDecay));
            }
            $adjustedScores[] = $adjusted;
            $total += $adjusted;
            $rank++;
        }

        $rewards = [];
        $remainingBudget = $baseBudget;
        foreach ($eligibleTop as $i => $mod) {
            $reward = 0.0;
            if ($total > 0) {
                $reward = ($baseBudget * $adjustedScores[$i]) / $total;
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

        if ($premium) {
            $maxShare = (float)($context['max_share'] ?? ($premiumReward['max_share'] ?? 0));
            if ($maxShare > 0 && $baseBudget > 0) {
                $rewards = $this->applyRewardCap($rewards, $baseBudget, $maxShare);
            }
        }

        if (!empty($bonusMap)) {
            foreach ($rewards as $i => $mod) {
                $bonus = (float)($bonusMap[$mod['user_id']] ?? 0);
                if ($bonus > 0) {
                    $rewards[$i]['bonus'] = round($bonus, 2);
                    $rewards[$i]['reward'] += $bonus;
                } else {
                    $rewards[$i]['bonus'] = 0.0;
                }
            }
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
                $mod['bonus'] = 0.0;
                $final[] = $mod;
            }
        }

        return $final;
    }

    private function buildBonusMap(array $mods, float $bonusPool, array $bonusSplit): array
    {
        if ($bonusPool <= 0) {
            return [];
        }
        $splitTotal = 0.0;
        foreach ($bonusSplit as $value) {
            $splitTotal += (float)$value;
        }
        if ($splitTotal <= 0) {
            return [];
        }

        $eligible = array_values(array_filter($mods, static function (array $mod): bool {
            return !empty($mod['eligible']);
        }));
        if (empty($eligible)) {
            return [];
        }

        $topMod = null;
        $mostActive = null;
        $mostImproved = null;
        foreach ($eligible as $mod) {
            if ($topMod === null || ($mod['score'] ?? 0) > ($topMod['score'] ?? 0)) {
                $topMod = $mod;
            }
            if ($mostActive === null || ($mod['active_minutes'] ?? 0) > ($mostActive['active_minutes'] ?? 0)) {
                $mostActive = $mod;
            }
            if (($mod['improvement'] ?? null) !== null) {
                if ($mostImproved === null || ($mod['improvement'] ?? 0) > ($mostImproved['improvement'] ?? 0)) {
                    $mostImproved = $mod;
                }
            }
        }

        $winners = [
            'top_mod' => $topMod,
            'most_active' => $mostActive,
            'most_improved' => $mostImproved,
        ];

        $bonusMap = [];
        foreach ($winners as $key => $winner) {
            if (!$winner) {
                continue;
            }
            $split = (float)($bonusSplit[$key] ?? 0);
            if ($split <= 0) {
                continue;
            }
            $amount = $bonusPool * ($split / $splitTotal);
            $userId = (int)$winner['user_id'];
            $bonusMap[$userId] = ($bonusMap[$userId] ?? 0) + $amount;
        }

        return $bonusMap;
    }

    private function applyRewardCap(array $rewards, float $budget, float $maxShare): array
    {
        $cap = $budget * $maxShare;
        if ($cap <= 0) {
            return $rewards;
        }

        $iteration = 0;
        do {
            $overflow = 0.0;
            $poolTotal = 0.0;
            $poolIndexes = [];

            foreach ($rewards as $i => $mod) {
                if (($mod['reward'] ?? 0) > $cap) {
                    $overflow += $mod['reward'] - $cap;
                    $rewards[$i]['reward'] = $cap;
                } else {
                    $poolTotal += $mod['reward'];
                    $poolIndexes[] = $i;
                }
            }

            if ($overflow > 0 && $poolTotal > 0 && !empty($poolIndexes)) {
                foreach ($poolIndexes as $i) {
                    $rewards[$i]['reward'] += ($rewards[$i]['reward'] / $poolTotal) * $overflow;
                }
            } else {
                break;
            }
            $iteration++;
        } while ($overflow > 0 && $iteration < 5);

        $total = 0.0;
        foreach ($rewards as $i => $mod) {
            $rewards[$i]['reward'] = round($mod['reward'], 2);
            $total += $rewards[$i]['reward'];
        }
        $delta = round($budget - $total, 2);
        if (!empty($rewards)) {
            $rewards[count($rewards) - 1]['reward'] = round($rewards[count($rewards) - 1]['reward'] + $delta, 2);
        }

        return $rewards;
    }
}
