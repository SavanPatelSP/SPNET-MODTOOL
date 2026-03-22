<?php

namespace App\Services;

class RewardService
{
    private array $config;
    private array $auditConfig;
    private ?AuditLogService $audit = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->auditConfig = $config['audit'] ?? [];
    }

    public function setAuditLogger(?AuditLogService $audit): void
    {
        $this->audit = $audit;
    }

    public function rankAndReward(array $mods, float $budget, array $context = []): array
    {
        $eligibility = $this->config['eligibility'] ?? [];
        $minDays = (int)($eligibility['min_days_active'] ?? 0);
        $minMessages = (int)($eligibility['min_messages'] ?? 0);
        $minScore = (float)($eligibility['min_score'] ?? 0);
        $minActions = (int)($eligibility['min_actions'] ?? 0);
        $minActiveHours = (float)($eligibility['min_active_hours'] ?? 0);

        $rewardConfig = $this->config['reward'] ?? [];
        $topN = $rewardConfig['top_n'] ?? count($mods);
        $topN = min($topN, count($mods));
        $rankMultipliers = $rewardConfig['rank_multipliers'] ?? [];
        $minReward = (float)($rewardConfig['min_reward'] ?? 0);
        $bonusPercent = (float)($rewardConfig['kpi_bonus_percent'] ?? 0);
        $bonusSplit = $rewardConfig['kpi_bonus_split'] ?? [];
        $rankBy = strtolower((string)($rewardConfig['rank_by'] ?? 'score'));
        $scoreExponent = (float)($rewardConfig['score_exponent'] ?? 0.85);
        $baseStipendPercent = (float)($rewardConfig['base_stipend_percent'] ?? 0.1);
        $scoreComponents = $rewardConfig['score_components'] ?? [
            'score' => 0.7,
            'impact' => 0.2,
            'consistency' => 0.1,
        ];
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

        $eligibleForRanges = array_values(array_filter($eligible, static fn(array $mod): bool => !empty($mod['eligible'])));
        if (empty($eligibleForRanges)) {
            $eligibleForRanges = $eligible;
        }

        $scoreComponents = $this->normalizeScoreComponents($scoreComponents);
        $ranges = $this->buildComponentRanges($eligibleForRanges, $scoreComponents);
        foreach ($eligible as &$mod) {
            $mod['reward_score'] = $this->computeRewardScore($mod, $scoreComponents, $ranges);
        }
        unset($mod);

        usort($eligible, function (array $a, array $b) use ($rankBy): int {
            $aVal = $this->getRankValue($a, $rankBy);
            $bVal = $this->getRankValue($b, $rankBy);
            if ($aVal === $bVal) {
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            }
            return $bVal <=> $aVal;
        });

        $eligibleOrder = [];
        foreach ($eligible as $mod) {
            if ($mod['eligible']) {
                $eligibleOrder[] = $mod;
            }
        }

        $eligibleTop = array_slice($eligibleOrder, 0, $topN);
        $topIndex = [];
        foreach ($eligibleTop as $i => $mod) {
            $topIndex[$mod['user_id']] = $i;
        }

        $bonusMap = $this->buildBonusMap($eligible, $bonusPool, $bonusSplit);

        $adjustedScores = [];
        $adjustedMap = [];
        $total = 0.0;
        $rank = 1;
        foreach ($eligibleTop as $mod) {
            $multiplier = $rankMultipliers[$rank] ?? 1.0;
            $baseScore = $mod['reward_score'] ?? ($mod['score'] ?? 0.0);
            $baseScore = max(0.0, (float)$baseScore);
            $adjusted = $scoreExponent !== 1.0 ? pow($baseScore, $scoreExponent) : $baseScore;
            $adjusted *= $multiplier;
            if ($premium && isset($stabilityBonus[$mod['user_id']])) {
                $adjusted *= (1 + (float)$stabilityBonus[$mod['user_id']]);
            }
            if ($premium && $penaltyWeight > 0 && ($mod['improvement'] ?? 0) < 0) {
                $penalty = min($penaltyWeight, (abs($mod['improvement']) / 100) * $penaltyWeight);
                $adjusted *= max(0.0, 1 - ($penalty * $penaltyDecay));
            }
            $adjustedScores[] = $adjusted;
            $adjustedMap[$mod['user_id']] = $adjusted;
            $total += $adjusted;
            $rank++;
        }

        $eligibleCount = count($eligibleOrder);
        $baseStipendPercent = max(0.0, min(0.8, $baseStipendPercent));
        $stipendPool = $eligibleCount > 0 ? ($baseBudget * $baseStipendPercent) : 0.0;
        $performanceBudget = max(0.0, $baseBudget - $stipendPool);
        $stipendPer = $eligibleCount > 0 ? ($stipendPool / $eligibleCount) : 0.0;

        $rewards = [];
        foreach ($eligibleOrder as $mod) {
            $reward = $stipendPer;
            if (isset($topIndex[$mod['user_id']])) {
                $i = $topIndex[$mod['user_id']];
                if ($total > 0) {
                    $reward += ($performanceBudget * $adjustedScores[$i]) / $total;
                }
                if ($minReward > 0) {
                    $reward = max($minReward, $reward);
                }
            }
            $mod['reward'] = $reward;
            $mod['bonus'] = 0.0;
            $rewards[] = $mod;
        }

        if ($premium) {
            $maxShare = (float)($context['max_share'] ?? ($premiumReward['max_share'] ?? 0));
            if ($maxShare > 0 && $baseBudget > 0) {
                $rewards = $this->applyRewardCap($rewards, $baseBudget, $maxShare);
            }
        }

        if (!empty($rewards) && (!$premium || ($context['max_share'] ?? ($premiumReward['max_share'] ?? 0)) <= 0)) {
            $rewards = $this->finalizeRewards($rewards, $baseBudget);
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

        $this->logScoreAudit(
            $final,
            $context,
            $rankBy,
            $scoreComponents,
            $ranges,
            $adjustedMap,
            $budget,
            $baseBudget,
            $bonusPool,
            $topN,
            $scoreExponent,
            $baseStipendPercent,
            $minReward
        );

        return $final;
    }

    private function logScoreAudit(
        array $mods,
        array $context,
        string $rankBy,
        array $scoreComponents,
        array $ranges,
        array $adjustedMap,
        float $budget,
        float $baseBudget,
        float $bonusPool,
        int $topN,
        float $scoreExponent,
        float $baseStipendPercent,
        float $minReward
    ): void {
        if ($this->audit === null || empty($this->auditConfig['score_log_enabled'])) {
            return;
        }

        $actorId = (int)($context['actor_id'] ?? 0);
        $chatId = isset($context['chat_id']) ? (int)$context['chat_id'] : null;
        $level = strtolower((string)($this->auditConfig['score_log_level'] ?? 'detailed'));
        if (!in_array($level, ['summary', 'detailed'], true)) {
            $level = 'detailed';
        }

        $rankMap = [];
        $rank = 1;
        foreach ($mods as $mod) {
            if (!empty($mod['eligible'])) {
                $rankMap[$mod['user_id']] = $rank;
                $rank++;
            }
        }

        $entries = [];
        foreach ($mods as $mod) {
            $actions = (int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0);
            $entries[] = [
                'user_id' => $mod['user_id'],
                'name' => $mod['display_name'] ?? null,
                'eligible' => (bool)($mod['eligible'] ?? true),
                'rank' => $rankMap[$mod['user_id']] ?? null,
                'score' => (float)($mod['score'] ?? 0),
                'reward_score' => $mod['reward_score'] ?? null,
                'impact_score' => (float)($mod['impact_score'] ?? 0),
                'consistency_index' => (float)($mod['consistency_index'] ?? 0),
                'messages' => (int)($mod['messages'] ?? 0),
                'active_minutes' => (float)($mod['active_minutes'] ?? 0),
                'days_active' => (int)($mod['days_active'] ?? 0),
                'actions' => $actions,
                'role_multiplier' => (float)($mod['role_multiplier'] ?? 1.0),
                'adjusted_score' => $adjustedMap[$mod['user_id']] ?? null,
                'reward' => (float)($mod['reward'] ?? 0),
                'bonus' => (float)($mod['bonus'] ?? 0),
            ];
        }

        if ($level === 'summary') {
            usort($entries, fn($a, $b) => ($b['reward'] ?? 0) <=> ($a['reward'] ?? 0));
            $entries = array_slice($entries, 0, 10);
        }

        $meta = [
            'source' => $context['source'] ?? null,
            'month' => $context['month'] ?? null,
            'budget' => $budget,
            'base_budget' => $baseBudget,
            'bonus_pool' => $bonusPool,
            'rank_by' => $rankBy,
            'score_exponent' => $scoreExponent,
            'base_stipend_percent' => $baseStipendPercent,
            'min_reward' => $minReward,
            'top_n' => $topN,
            'score_components' => $scoreComponents,
            'component_ranges' => $ranges,
            'eligible_total' => count(array_filter($mods, static fn($mod) => !empty($mod['eligible']))),
            'total_mods' => count($mods),
            'mods' => $entries,
        ];

        $this->audit->log('score_calc', $actorId, $chatId, $meta);
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

    private function finalizeRewards(array $rewards, float $budget): array
    {
        $total = 0.0;
        foreach ($rewards as $i => $mod) {
            $rewards[$i]['reward'] = round((float)($mod['reward'] ?? 0), 2);
            $total += $rewards[$i]['reward'];
        }
        $delta = round($budget - $total, 2);
        if (!empty($rewards)) {
            $last = count($rewards) - 1;
            $rewards[$last]['reward'] = round($rewards[$last]['reward'] + $delta, 2);
        }
        return $rewards;
    }

    private function normalizeScoreComponents(array $components): array
    {
        $clean = [];
        foreach ($components as $key => $weight) {
            $weight = (float)$weight;
            if ($weight <= 0) {
                continue;
            }
            $clean[(string)$key] = $weight;
        }
        if (empty($clean)) {
            return ['score' => 1.0];
        }
        return $clean;
    }

    private function buildComponentRanges(array $mods, array $components): array
    {
        $ranges = [];
        foreach ($components as $key => $weight) {
            $min = null;
            $max = null;
            foreach ($mods as $mod) {
                $value = $this->getComponentValue($mod, $key);
                if ($min === null || $value < $min) {
                    $min = $value;
                }
                if ($max === null || $value > $max) {
                    $max = $value;
                }
            }
            $ranges[$key] = [
                'min' => $min ?? 0.0,
                'max' => $max ?? 0.0,
            ];
        }
        return $ranges;
    }

    private function computeRewardScore(array $mod, array $components, array $ranges): float
    {
        $weightSum = 0.0;
        $score = 0.0;
        foreach ($components as $key => $weight) {
            $weight = (float)$weight;
            if ($weight <= 0) {
                continue;
            }
            $range = $ranges[$key] ?? ['min' => 0.0, 'max' => 0.0];
            $value = $this->getComponentValue($mod, $key);
            $normalized = $this->normalizeValue($value, (float)$range['min'], (float)$range['max']);
            $score += $normalized * $weight;
            $weightSum += $weight;
        }
        if ($weightSum <= 0) {
            return 0.0;
        }
        return round($score / $weightSum, 4);
    }

    private function getComponentValue(array $mod, string $key): float
    {
        switch (strtolower($key)) {
            case 'impact':
            case 'impact_score':
                return (float)($mod['impact_score'] ?? 0);
            case 'consistency':
            case 'consistency_index':
                return (float)($mod['consistency_index'] ?? 0);
            case 'actions':
            case 'actions_total':
                return (float)($mod['actions_total'] ?? (($mod['warnings'] ?? 0) + ($mod['mutes'] ?? 0) + ($mod['bans'] ?? 0)));
            case 'active_minutes':
                return (float)($mod['active_minutes'] ?? 0);
            case 'messages':
                return (float)($mod['messages'] ?? 0);
            case 'days_active':
                return (float)($mod['days_active'] ?? 0);
            case 'score':
            default:
                return (float)($mod['score'] ?? 0);
        }
    }

    private function normalizeValue(float $value, float $min, float $max): float
    {
        if ($max <= $min) {
            return $max > 0 ? 1.0 : 0.0;
        }
        return ($value - $min) / ($max - $min);
    }

    private function getRankValue(array $mod, string $rankBy): float
    {
        switch ($rankBy) {
            case 'reward_score':
                return (float)($mod['reward_score'] ?? 0);
            case 'impact':
            case 'impact_score':
                return (float)($mod['impact_score'] ?? 0);
            case 'consistency':
            case 'consistency_index':
                return (float)($mod['consistency_index'] ?? 0);
            case 'score':
            default:
                return (float)($mod['score'] ?? 0);
        }
    }
}
