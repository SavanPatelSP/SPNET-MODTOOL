<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

class CoachingService
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
        if ($month) {
            $bundle = $this->stats->getMonthlyStats($chatId, $month);
        } else {
            $bundle = $this->stats->getMonthToDateStats($chatId);
        }

        $mods = $bundle['mods'] ?? [];
        $range = $bundle['range'];
        $timezone = $bundle['timezone'] ?? ($this->config['timezone'] ?? 'UTC');
        $nowLocal = new DateTimeImmutable('now', new DateTimeZone($timezone));

        $daysInRange = max(1, (int)$range['end_local']->diff($range['start_local'])->format('%a'));
        $inactiveThreshold = (int)($this->config['premium']['health']['inactive_days_alert'] ?? 7);

        $tips = [];
        $missed = [];
        foreach ($mods as &$mod) {
            $consistency = round(($mod['days_active'] ?? 0) / $daysInRange * 100, 1);
            $mod['consistency'] = $consistency;

            $lastActiveAt = $mod['last_active_at'] ?? null;
            $daysSince = null;
            if ($lastActiveAt) {
                $last = new DateTimeImmutable($lastActiveAt, new DateTimeZone('UTC'));
                $last = $last->setTimezone(new DateTimeZone($timezone));
                $daysSince = (int)$last->diff($nowLocal)->format('%a');
            }
            $mod['days_since_active'] = $daysSince;

            $actions = (int)(($mod['warnings'] ?? 0) + ($mod['mutes'] ?? 0) + ($mod['bans'] ?? 0));
            $messages = (int)($mod['messages'] ?? 0);
            $activeHours = (float)($mod['active_minutes'] ?? 0) / 60;

            if ($daysSince !== null && $daysSince >= $inactiveThreshold) {
                $missed[] = $mod['display_name'] . ' (' . $daysSince . ' days inactive)';
            }

            if ($consistency < 35) {
                $tips[] = $mod['display_name'] . ': aim for more consistent days (consistency ' . $consistency . '%).';
            } elseif ($messages > 150 && $actions < 3) {
                $tips[] = $mod['display_name'] . ': consider more visible moderation actions.';
            } elseif ($actions > 10 && $messages < 40) {
                $tips[] = $mod['display_name'] . ': balance moderation with chat presence.';
            } elseif ($activeHours < 4) {
                $tips[] = $mod['display_name'] . ': increase active presence hours.';
            }
        }
        unset($mod);

        return [
            'range' => $range,
            'mods' => $mods,
            'tips' => array_values(array_unique($tips)),
            'missed' => $missed,
        ];
    }
}
