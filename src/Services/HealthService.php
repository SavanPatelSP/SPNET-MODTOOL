<?php

namespace App\Services;

class HealthService
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
        $bundle = $month ? $this->stats->getMonthlyStats($chatId, $month) : $this->stats->getMonthToDateStats($chatId);
        $mods = $bundle['mods'] ?? [];
        $range = $bundle['range'];
        $timezone = $bundle['timezone'] ?? ($this->config['timezone'] ?? 'UTC');

        $modIds = array_map(static fn($mod) => (int)$mod['user_id'], $mods);
        $coverage = $this->stats->getHourlyCoverage($chatId, $range, $timezone, $modIds);

        $totalMessages = 0;
        $totalActions = 0;
        $totalActiveHours = 0.0;
        foreach ($mods as $mod) {
            $totalMessages += (int)($mod['messages'] ?? 0);
            $totalActions += (int)(($mod['warnings'] ?? 0) + ($mod['mutes'] ?? 0) + ($mod['bans'] ?? 0));
            $totalActiveHours += (float)($mod['active_minutes'] ?? 0) / 60;
        }

        $topShare = 0.0;
        $topMod = null;
        foreach ($mods as $mod) {
            if ($totalMessages <= 0) {
                continue;
            }
            $share = ($mod['messages'] ?? 0) / $totalMessages;
            if ($share > $topShare) {
                $topShare = $share;
                $topMod = $mod;
            }
        }

        $avgActive = count($mods) > 0 ? $totalActiveHours / count($mods) : 0.0;
        $burnoutMultiplier = (float)($this->config['premium']['health']['burnout_multiplier'] ?? 1.8);
        $burnout = [];
        foreach ($mods as $mod) {
            $hours = (float)($mod['active_minutes'] ?? 0) / 60;
            if ($avgActive > 0 && $hours >= ($avgActive * $burnoutMultiplier)) {
                $burnout[] = $mod['display_name'] . ' (' . number_format($hours, 1) . 'h)';
            }
        }

        $gapHours = (int)($this->config['premium']['health']['coverage_gap_hours'] ?? 0);
        $coverageGaps = [];
        if ($gapHours > 0) {
            $rangeEnd = $range['end_utc'] ?? gmdate('Y-m-d H:i:s');
            $endTs = strtotime($rangeEnd) ?: time();
            foreach ($mods as $mod) {
                $lastActive = $mod['last_active_at'] ?? null;
                if (!$lastActive) {
                    $coverageGaps[] = $mod['display_name'] . ' (no activity)';
                    continue;
                }
                $lastTs = strtotime($lastActive);
                if ($lastTs && (($endTs - $lastTs) / 3600) >= $gapHours) {
                    $hoursAgo = number_format(($endTs - $lastTs) / 3600, 1);
                    $coverageGaps[] = $mod['display_name'] . ' (' . $hoursAgo . 'h ago)';
                }
            }
        }

        return [
            'range' => $range,
            'coverage' => $coverage,
            'top_share' => $topShare,
            'top_mod' => $topMod,
            'burnout' => $burnout,
            'coverage_gaps' => $coverageGaps,
            'total_messages' => $totalMessages,
            'total_actions' => $totalActions,
        ];
    }
}
