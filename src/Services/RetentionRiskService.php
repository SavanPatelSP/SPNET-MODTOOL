<?php

namespace App\Services;

class RetentionRiskService
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

    public function buildReport(int|string $chatId, ?string $month = null, ?float $thresholdOverride = null): array
    {
        $bundle = $this->stats->getMonthlyStats($chatId, $month);
        $mods = $bundle['mods'] ?? [];
        if (empty($mods)) {
            return [
                'status' => 'no_mods',
                'range' => $bundle['range'] ?? [],
                'prev_range' => null,
                'threshold' => $thresholdOverride ?? 0,
                'risks' => [],
                'summary' => [],
            ];
        }

        $range = $bundle['range'];
        $prevMonth = $range['start_local']->modify('-1 month')->format('Y-m');
        $prevBundle = $this->stats->getMonthlyStats($chatId, $prevMonth);
        $prevMods = $prevBundle['mods'] ?? [];
        $prevMap = [];
        foreach ($prevMods as $prev) {
            $prevMap[(int)$prev['user_id']] = $prev;
        }

        $settings = $this->settings->get($chatId);
        $defaults = $this->config['retention_alert_defaults'] ?? [];
        $threshold = $thresholdOverride ?? (float)($settings['retention_threshold'] ?? ($defaults['threshold'] ?? 30));

        $rules = $this->config['retention_alert'] ?? [];
        $minPrevScore = (float)($rules['min_prev_score'] ?? 10);
        $minPrevMessages = (int)($rules['min_prev_messages'] ?? 50);
        $minPrevActiveHours = (float)($rules['min_prev_active_hours'] ?? 5);
        $minPrevActions = (int)($rules['min_prev_actions'] ?? 2);
        $high = (float)($rules['severity_high'] ?? 50);
        $medium = (float)($rules['severity_medium'] ?? 35);
        $low = (float)($rules['severity_low'] ?? 25);

        $risks = [];
        foreach ($mods as $mod) {
            $userId = (int)$mod['user_id'];
            $prev = $prevMap[$userId] ?? null;
            if (!$prev) {
                continue;
            }

            $prevScore = (float)($prev['score'] ?? 0);
            $prevMessages = (int)($prev['messages'] ?? 0);
            $prevActiveHours = (float)($prev['active_minutes'] ?? 0) / 60;
            $prevActions = (int)($prev['actions_total'] ?? ((int)($prev['warnings'] ?? 0) + (int)($prev['mutes'] ?? 0) + (int)($prev['bans'] ?? 0)));

            $baselineOk = ($prevScore >= $minPrevScore) ||
                ($prevMessages >= $minPrevMessages) ||
                ($prevActiveHours >= $minPrevActiveHours) ||
                ($prevActions >= $minPrevActions);

            if (!$baselineOk) {
                continue;
            }

            $currScore = (float)($mod['score'] ?? 0);
            $currMessages = (int)($mod['messages'] ?? 0);
            $currActiveHours = (float)($mod['active_minutes'] ?? 0) / 60;
            $currActions = (int)($mod['actions_total'] ?? ((int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0)));

            $dropScore = $this->percentDrop($currScore, $prevScore);
            $dropMessages = $this->percentDrop($currMessages, $prevMessages);
            $dropActive = $this->percentDrop($currActiveHours, $prevActiveHours);
            $dropActions = $this->percentDrop($currActions, $prevActions);

            $dropValues = array_filter([$dropScore, $dropMessages, $dropActive, $dropActions], static fn($v) => $v !== null);
            if (empty($dropValues)) {
                continue;
            }
            $maxDrop = max($dropValues);
            if ($maxDrop < $threshold) {
                continue;
            }

            $severity = 'LOW';
            $severityRank = 1;
            if ($maxDrop >= $high) {
                $severity = 'HIGH';
                $severityRank = 3;
            } elseif ($maxDrop >= $medium) {
                $severity = 'MEDIUM';
                $severityRank = 2;
            } elseif ($maxDrop >= $low) {
                $severity = 'LOW';
                $severityRank = 1;
            }

            $risks[] = [
                'user_id' => $userId,
                'display_name' => $mod['display_name'],
                'role' => $mod['role'] ?? null,
                'severity' => $severity,
                'severity_rank' => $severityRank,
                'max_drop' => $maxDrop,
                'score_drop' => $dropScore,
                'messages_drop' => $dropMessages,
                'active_drop' => $dropActive,
                'actions_drop' => $dropActions,
                'prev_score' => $prevScore,
                'curr_score' => $currScore,
                'prev_messages' => $prevMessages,
                'curr_messages' => $currMessages,
                'prev_active_hours' => $prevActiveHours,
                'curr_active_hours' => $currActiveHours,
                'prev_actions' => $prevActions,
                'curr_actions' => $currActions,
                'trend' => $mod['trend_3m'] ?? $mod['improvement'] ?? null,
            ];
        }

        usort($risks, static function (array $a, array $b): int {
            if ($a['severity_rank'] !== $b['severity_rank']) {
                return $b['severity_rank'] <=> $a['severity_rank'];
            }
            if ($a['max_drop'] !== $b['max_drop']) {
                return $b['max_drop'] <=> $a['max_drop'];
            }
            return $b['score_drop'] <=> $a['score_drop'];
        });

        return [
            'status' => empty($prevMods) ? 'no_prev' : 'ok',
            'range' => $bundle['range'] ?? [],
            'prev_range' => $prevBundle['range'] ?? null,
            'threshold' => $threshold,
            'risks' => $risks,
            'summary' => [
                'total_mods' => count($mods),
                'flagged' => count($risks),
            ],
        ];
    }

    public function buildLines(array $report, string $chatTitle): array
    {
        $rangeLabel = $report['range']['label'] ?? '';
        $prevLabel = $report['prev_range']['label'] ?? null;
        $threshold = (float)($report['threshold'] ?? 0);
        $lines = [];
        $lines[] = '<b>Retention Risk Alerts</b>';
        $lines[] = htmlspecialchars($chatTitle, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($rangeLabel, ENT_QUOTES, 'UTF-8');
        if ($prevLabel) {
            $lines[] = 'Compared to: ' . htmlspecialchars($prevLabel, ENT_QUOTES, 'UTF-8');
        }
        $lines[] = 'Rule: flag if any metric drops ≥ ' . number_format($threshold, 0) . '% with prior baseline.';
        $lines[] = '';

        if (empty($report['risks'])) {
            if (($report['status'] ?? '') === 'no_prev') {
                $lines[] = 'No prior month data found for comparison yet.';
            } else {
                $lines[] = 'No retention risks detected this month.';
            }
            return $lines;
        }

        $rank = 1;
        foreach (array_slice($report['risks'], 0, 12) as $risk) {
            $name = htmlspecialchars((string)$risk['display_name'], ENT_QUOTES, 'UTF-8');
            $severity = $risk['severity'] ?? 'LOW';
            $role = $risk['role'] ? ' (' . htmlspecialchars((string)$risk['role'], ENT_QUOTES, 'UTF-8') . ')' : '';
            $lines[] = $rank . '. [' . $severity . '] ' . $name . $role;

            $scoreDrop = $this->formatDrop($risk['score_drop'], $risk['prev_score'], $risk['curr_score']);
            $msgDrop = $this->formatDrop($risk['messages_drop'], $risk['prev_messages'], $risk['curr_messages']);
            $activeDrop = $this->formatDrop($risk['active_drop'], $risk['prev_active_hours'], $risk['curr_active_hours'], 'h');
            $actionDrop = $this->formatDrop($risk['actions_drop'], $risk['prev_actions'], $risk['curr_actions']);

            $line = 'Score ' . $scoreDrop . ' | Msgs ' . $msgDrop . ' | Active ' . $activeDrop . ' | Actions ' . $actionDrop;
            if ($risk['trend'] !== null) {
                $trend = (float)$risk['trend'];
                $line .= ' | Trend ' . ($trend >= 0 ? '+' : '') . number_format($trend, 1) . '%';
            }
            $lines[] = $line;
            $lines[] = '';
            $rank++;
        }

        return $lines;
    }

    private function percentDrop(float|int $current, float|int $previous): ?float
    {
        $previous = (float)$previous;
        $current = (float)$current;
        if ($previous <= 0) {
            return null;
        }
        $drop = (($previous - $current) / $previous) * 100;
        if ($drop <= 0) {
            return 0.0;
        }
        return round($drop, 1);
    }

    private function formatDrop(?float $drop, float|int $prev, float|int $curr, string $suffix = ''): string
    {
        $prevVal = is_float($prev) ? number_format((float)$prev, 1) : number_format((int)$prev);
        $currVal = is_float($curr) ? number_format((float)$curr, 1) : number_format((int)$curr);
        $dropVal = $drop === null ? '0' : number_format((float)$drop, 1);
        return '-' . $dropVal . '% (' . $prevVal . $suffix . '→' . $currVal . $suffix . ')';
    }
}
