<?php

namespace App\Services;

class PerformanceReviewService
{
    private StatsService $stats;
    private SettingsService $settings;
    private array $config;

    public function __construct(StatsService $stats, SettingsService $settings, array $config)
    {
        $this->stats = $stats;
        $this->settings = $settings;
        $this->config = $config;
    }

    public function buildReport(int|string $chatId, ?string $month = null): array
    {
        $bundle = $this->stats->getMonthlyStats($chatId, $month);
        $mods = $bundle['mods'] ?? [];
        if (empty($mods)) {
            return [
                'range' => $bundle['range'] ?? [],
                'timezone' => $bundle['timezone'] ?? ($this->config['timezone'] ?? 'UTC'),
                'reviews' => [],
                'summary' => [],
            ];
        }

        $scores = [];
        $messages = [];
        $activeHours = [];
        $actions = [];
        $daysActive = [];
        foreach ($mods as $mod) {
            $scores[] = (float)($mod['score'] ?? 0);
            $messages[] = (int)($mod['messages'] ?? 0);
            $activeHours[] = (float)($mod['active_minutes'] ?? 0) / 60;
            $actions[] = (int)($mod['actions_total'] ?? ((int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0)));
            $daysActive[] = (int)($mod['days_active'] ?? 0);
        }

        $summary = [
            'score_p25' => $this->percentile($scores, 25),
            'score_p50' => $this->percentile($scores, 50),
            'score_p75' => $this->percentile($scores, 75),
            'messages_p25' => $this->percentile($messages, 25),
            'messages_p50' => $this->percentile($messages, 50),
            'messages_p75' => $this->percentile($messages, 75),
            'active_p25' => $this->percentile($activeHours, 25),
            'active_p50' => $this->percentile($activeHours, 50),
            'active_p75' => $this->percentile($activeHours, 75),
            'actions_p25' => $this->percentile($actions, 25),
            'actions_p50' => $this->percentile($actions, 50),
            'actions_p75' => $this->percentile($actions, 75),
            'days_p25' => $this->percentile($daysActive, 25),
            'days_p50' => $this->percentile($daysActive, 50),
            'days_p75' => $this->percentile($daysActive, 75),
        ];

        $topModId = $mods[0]['user_id'] ?? null;
        $mostActiveId = null;
        $mostActiveValue = -1.0;
        $mostImprovedId = null;
        $mostImprovedValue = null;

        foreach ($mods as $mod) {
            $hours = (float)($mod['active_minutes'] ?? 0) / 60;
            if ($hours > $mostActiveValue) {
                $mostActiveValue = $hours;
                $mostActiveId = $mod['user_id'];
            }
            $improvement = $mod['improvement'] ?? null;
            if ($improvement !== null) {
                if ($mostImprovedValue === null || $improvement > $mostImprovedValue) {
                    $mostImprovedValue = $improvement;
                    $mostImprovedId = $mod['user_id'];
                }
            }
        }

        $reviewConfig = $this->config['ai_review'] ?? [];
        $lowConsistency = (float)($reviewConfig['low_consistency'] ?? 35.0);
        $highConsistency = (float)($reviewConfig['high_consistency'] ?? 60.0);
        $trendGood = (float)($reviewConfig['trend_good'] ?? 5.0);
        $trendBad = (float)($reviewConfig['trend_bad'] ?? -5.0);
        $eligibility = $this->config['eligibility'] ?? [];
        $minDays = (int)($eligibility['min_days_active'] ?? 0);
        $minMessages = (int)($eligibility['min_messages'] ?? 0);
        $minActions = (int)($eligibility['min_actions'] ?? 0);

        $reviews = [];
        foreach ($mods as $mod) {
            $actionsCount = (int)($mod['actions_total'] ?? ((int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0)));
            $activeHoursValue = (float)($mod['active_minutes'] ?? 0) / 60;
            $days = (int)($mod['days_active'] ?? 0);
            $messagesValue = (int)($mod['messages'] ?? 0);
            $consistency = (float)($mod['consistency_index'] ?? 0);
            $trend = $mod['trend_3m'] ?? $mod['improvement'] ?? null;

            $labels = [];
            if ($topModId !== null && (int)$mod['user_id'] === (int)$topModId) {
                $labels[] = 'Top Performer';
            }
            if ($mostActiveId !== null && (int)$mod['user_id'] === (int)$mostActiveId) {
                $labels[] = 'Most Active';
            }
            if ($mostImprovedId !== null && (int)$mod['user_id'] === (int)$mostImprovedId) {
                $labels[] = 'Most Improved';
            }

            $strengths = [];
            if ($messagesValue >= $summary['messages_p75'] || $activeHoursValue >= $summary['active_p75']) {
                $strengths[] = 'Strong chat presence (' . $messagesValue . ' msgs, ' . number_format($activeHoursValue, 1) . 'h active).';
            }
            if ($actionsCount >= $summary['actions_p75'] && $actionsCount > 0) {
                $strengths[] = 'Decisive moderation (' . $actionsCount . ' actions).';
            }
            if ($consistency >= $highConsistency) {
                $strengths[] = 'Reliable coverage (' . number_format($consistency, 1) . '% days active).';
            }
            if ($trend !== null && $trend >= $trendGood) {
                $strengths[] = 'Upward trend (+' . number_format($trend, 1) . '%).';
            }
            if (empty($strengths)) {
                $strengths[] = 'Steady participation with room to grow.';
            }

            $focus = [];
            if ($consistency < $lowConsistency) {
                $focus[] = 'Increase active days for steadier coverage.';
            }
            if ($messagesValue < $summary['messages_p25'] && $activeHoursValue < $summary['active_p25']) {
                $focus[] = 'Increase chat presence and responsiveness.';
            } elseif ($messagesValue < $summary['messages_p25']) {
                $focus[] = 'Post/respond more to boost chat presence.';
            } elseif ($activeHoursValue < $summary['active_p25']) {
                $focus[] = 'Spend more active time in the chat.';
            }
            if ($actionsCount < $summary['actions_p25']) {
                $focus[] = 'Take more visible moderation actions when needed.';
            }
            if ($trend !== null && $trend <= $trendBad) {
                $focus[] = 'Stabilize output and reverse the downward trend.';
            }
            if (empty($focus)) {
                $focus[] = 'Maintain pace and support complex cases.';
            }
            $focus = array_slice(array_values(array_unique($focus)), 0, 2);

            $target = '';
            if ($minDays > 0 && $days < $minDays) {
                $target = 'Reach at least ' . $minDays . ' active days.';
            } elseif ($minMessages > 0 && $messagesValue < $minMessages) {
                $target = 'Reach at least ' . $minMessages . ' messages.';
            } elseif ($minActions > 0 && $actionsCount < $minActions) {
                $target = 'Reach at least ' . $minActions . ' moderation actions.';
            } elseif ($consistency < $lowConsistency) {
                $target = 'Aim for ' . number_format(min(90.0, $lowConsistency + 10.0), 0) . '% days active.';
            } elseif ($activeHoursValue < $summary['active_p50']) {
                $target = 'Target ' . number_format(max($summary['active_p50'], $activeHoursValue + 2.0), 1) . 'h active time.';
            } elseif ($messagesValue < $summary['messages_p50']) {
                $target = 'Target ' . max((int)$summary['messages_p50'], $messagesValue + 20) . ' messages.';
            } elseif ($actionsCount < $summary['actions_p50']) {
                $target = 'Target ' . max(1, (int)$summary['actions_p50']) . ' moderation actions.';
            } else {
                $target = 'Maintain output and mentor newer mods.';
            }

            $reviews[] = [
                'user_id' => $mod['user_id'],
                'display_name' => $mod['display_name'],
                'role' => $mod['role'] ?? null,
                'labels' => $labels,
                'score' => (float)($mod['score'] ?? 0),
                'consistency' => $consistency,
                'trend' => $trend,
                'messages' => $messagesValue,
                'active_hours' => $activeHoursValue,
                'days_active' => $days,
                'warnings' => (int)($mod['warnings'] ?? 0),
                'mutes' => (int)($mod['mutes'] ?? 0),
                'bans' => (int)($mod['bans'] ?? 0),
                'actions_total' => $actionsCount,
                'strengths' => $strengths,
                'focus' => $focus,
                'target' => $target,
            ];
        }

        return [
            'range' => $bundle['range'] ?? [],
            'timezone' => $bundle['timezone'] ?? ($this->config['timezone'] ?? 'UTC'),
            'reviews' => $reviews,
            'summary' => $summary,
        ];
    }

    public function buildLines(array $report, string $chatTitle): array
    {
        $rangeLabel = $report['range']['label'] ?? '';
        $lines = [];
        $lines[] = '<b>AI Performance Review</b>';
        $lines[] = htmlspecialchars($chatTitle, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($rangeLabel, ENT_QUOTES, 'UTF-8');
        $lines[] = 'Automated summary based on chat activity, moderation actions, and consistency.';
        $lines[] = '';

        foreach ($report['reviews'] as $review) {
            $name = htmlspecialchars((string)$review['display_name'], ENT_QUOTES, 'UTF-8');
            $role = $review['role'] ? ' (' . htmlspecialchars((string)$review['role'], ENT_QUOTES, 'UTF-8') . ')' : '';
            $labels = '';
            if (!empty($review['labels'])) {
                $labels = ' [' . implode(', ', array_map(static function ($label) {
                    return htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
                }, $review['labels'])) . ']';
            }
            $lines[] = '<b>' . $name . '</b>' . $role . $labels;
            $lines[] = 'Score: ' . number_format((float)$review['score'], 2) .
                ' | Consistency: ' . number_format((float)$review['consistency'], 1) . '%';
            if ($review['trend'] !== null) {
                $trend = (float)$review['trend'];
                $lines[count($lines) - 1] .= ' | Trend: ' . ($trend >= 0 ? '+' : '') . number_format($trend, 1) . '%';
            }
            $lines[] = 'Chat work: ' . number_format((int)$review['messages']) . ' msgs | ' . number_format((float)$review['active_hours'], 1) . 'h active | ' . number_format((int)$review['days_active']) . ' days';
            $lines[] = 'Moderation: ' . number_format((int)$review['warnings']) . ' warns | ' . number_format((int)$review['mutes']) . ' mutes | ' . number_format((int)$review['bans']) . ' bans';
            $lines[] = 'Strengths: ' . htmlspecialchars($review['strengths'][0] ?? '', ENT_QUOTES, 'UTF-8');
            if (!empty($review['strengths'][1])) {
                $lines[] = 'Strengths+: ' . htmlspecialchars($review['strengths'][1], ENT_QUOTES, 'UTF-8');
            }
            $lines[] = 'Focus: ' . htmlspecialchars($review['focus'][0] ?? '', ENT_QUOTES, 'UTF-8');
            if (!empty($review['focus'][1])) {
                $lines[] = 'Focus+: ' . htmlspecialchars($review['focus'][1], ENT_QUOTES, 'UTF-8');
            }
            $lines[] = 'Target: ' . htmlspecialchars($review['target'], ENT_QUOTES, 'UTF-8');
            $lines[] = '';
        }

        return $lines;
    }

    private function percentile(array $values, float $percent): float
    {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $count = count($values);
        if ($count === 1) {
            return (float)$values[0];
        }
        $rank = ($percent / 100) * ($count - 1);
        $low = (int)floor($rank);
        $high = (int)ceil($rank);
        if ($low === $high) {
            return (float)$values[$low];
        }
        $weight = $rank - $low;
        return (float)$values[$low] + ((float)$values[$high] - (float)$values[$low]) * $weight;
    }
}
