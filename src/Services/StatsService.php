<?php

namespace App\Services;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;

class StatsService
{
    private Database $db;
    private SettingsService $settings;
    private array $config;

    public function __construct(Database $db, SettingsService $settings, array $config)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->config = $config;
    }

    public function getMonthlyStats(int|string $chatId, ?string $month = null): array
    {
        $settings = $this->settings->get($chatId);
        $timezone = $settings['timezone'] ?? ($this->config['timezone'] ?? 'UTC');

        $range = $this->monthRange($month, $timezone);
        $mods = $this->getMods($chatId);

        if (empty($mods)) {
            return [
                'mods' => [],
                'range' => $range,
                'timezone' => $timezone,
                'settings' => $settings,
                'summary' => [],
            ];
        }

        $stats = $this->buildStats($chatId, $mods, $range, $settings, $timezone);

        $prevMonth = $range['start_local']->modify('-1 month')->format('Y-m');
        $prevRange = $this->monthRange($prevMonth, $timezone);
        $prevStats = $this->buildStats($chatId, $mods, $prevRange, $settings, $timezone);

        $prevScores = [];
        foreach ($prevStats as $modStat) {
            $prevScores[$modStat['user_id']] = $modStat['score'];
        }

        foreach ($stats as &$modStat) {
            $prevScore = $prevScores[$modStat['user_id']] ?? 0.0;
            if ($prevScore > 0) {
                $modStat['improvement'] = (($modStat['score'] - $prevScore) / $prevScore) * 100.0;
            } elseif ($modStat['score'] > 0) {
                $modStat['improvement'] = 100.0;
            } else {
                $modStat['improvement'] = null;
            }
        }
        unset($modStat);

        $summary = $this->buildSummary($stats);

        return [
            'mods' => $stats,
            'range' => $range,
            'timezone' => $timezone,
            'settings' => $settings,
            'summary' => $summary,
        ];
    }

    private function buildStats(int|string $chatId, array $mods, array $range, array $settings, string $timezone): array
    {
        $modIds = array_map(fn($mod) => (int)$mod['user_id'], $mods);
        $modPlaceholders = implode(',', array_fill(0, count($modIds), '?'));

        $messageRows = $this->db->fetchAll(
            'SELECT user_id, sent_at FROM messages WHERE chat_id = ? AND sent_at >= ? AND sent_at < ? AND user_id IN (' . $modPlaceholders . ') ORDER BY sent_at ASC',
            array_merge([$chatId, $range['start_utc'], $range['end_utc']], $modIds)
        );

        $messagesByUser = [];
        foreach ($messageRows as $row) {
            $messagesByUser[$row['user_id']][] = $this->toTimestampUtc($row['sent_at']);
        }

        $actionRows = $this->db->fetchAll(
            'SELECT mod_id, action_type, COUNT(*) as count FROM actions WHERE chat_id = ? AND created_at >= ? AND created_at < ? AND mod_id IN (' . $modPlaceholders . ') GROUP BY mod_id, action_type',
            array_merge([$chatId, $range['start_utc'], $range['end_utc']], $modIds)
        );

        $actionsByUser = [];
        foreach ($actionRows as $row) {
            $actionsByUser[$row['mod_id']][$row['action_type']] = (int)$row['count'];
        }

        $membershipRows = $this->db->fetchAll(
            'SELECT user_id, joined_at, left_at FROM memberships WHERE chat_id = ? AND user_id IN (' . $modPlaceholders . ') AND joined_at < ? AND (left_at IS NULL OR left_at > ?)',
            array_merge([$chatId, $range['end_utc'], $range['start_utc']], $modIds)
        );

        $membershipsByUser = [];
        foreach ($membershipRows as $row) {
            $membershipsByUser[$row['user_id']][] = $row;
        }

        $externalStats = $this->getExternalStatsMap($chatId, $range['month']);

        $weights = $this->config['score_weights'] ?? [];
        $gap = (int)($settings['active_gap_minutes'] ?? $this->config['active_gap_minutes'] ?? 5);
        $floor = (int)($settings['active_floor_minutes'] ?? $this->config['active_floor_minutes'] ?? 1);

        $stats = [];
        foreach ($mods as $mod) {
            $userId = (int)$mod['user_id'];
            $timestamps = $messagesByUser[$userId] ?? [];

            $messageCount = count($timestamps);
            $externalMessageCount = (int)($externalStats[$userId]['messages'] ?? 0);
            $messageCount += $externalMessageCount;
            $activeMinutes = $this->computeActiveMinutes($timestamps, $gap, $floor);
            $daysActive = $this->computeDaysActive($timestamps, $timezone);
            $peakHour = $this->computePeakHour($timestamps, $timezone);
            $membershipMinutes = $this->computeMembershipMinutes($membershipsByUser[$userId] ?? [], $range);

            $actions = $actionsByUser[$userId] ?? [];
            $warnings = $actions['warn'] ?? 0;
            $mutes = $actions['mute'] ?? 0;
            $bans = $actions['ban'] ?? 0;
            $actionsTotal = $warnings + $mutes + $bans;

            $score = $this->computeScore([
                'messages' => $messageCount,
                'warnings' => $warnings,
                'mutes' => $mutes,
                'bans' => $bans,
                'active_minutes' => $activeMinutes,
                'membership_minutes' => $membershipMinutes,
                'days_active' => $daysActive,
            ], $weights);

            $stats[] = [
                'user_id' => $userId,
                'username' => $mod['username'],
                'first_name' => $mod['first_name'],
                'last_name' => $mod['last_name'],
                'display_name' => $this->displayName($mod),
                'messages' => $messageCount,
                'external_messages' => $externalMessageCount,
                'warnings' => $warnings,
                'mutes' => $mutes,
                'bans' => $bans,
                'actions_total' => $actionsTotal,
                'active_minutes' => $activeMinutes,
                'membership_minutes' => $membershipMinutes,
                'days_active' => $daysActive,
                'peak_hour' => $peakHour,
                'score' => $score,
            ];
        }

        usort($stats, fn($a, $b) => $b['score'] <=> $a['score']);

        return $stats;
    }

    private function getExternalStatsMap(int|string $chatId, string $month): array
    {
        $rows = $this->db->fetchAll(
            'SELECT user_id, SUM(messages) AS messages, SUM(replies) AS replies, SUM(reputation_take) AS reputation_take
             FROM external_user_stats
             WHERE chat_id = ? AND month = ?
             GROUP BY user_id',
            [$chatId, $month]
        );

        $map = [];
        foreach ($rows as $row) {
            $userId = (int)$row['user_id'];
            $map[$userId] = [
                'messages' => (int)($row['messages'] ?? 0),
                'replies' => (int)($row['replies'] ?? 0),
                'reputation_take' => (int)($row['reputation_take'] ?? 0),
            ];
        }

        return $map;
    }

    private function getMods(int|string $chatId): array
    {
        return $this->db->fetchAll(
            'SELECT u.id as user_id, u.username, u.first_name, u.last_name FROM chat_members cm JOIN users u ON u.id = cm.user_id WHERE cm.chat_id = ? AND cm.is_mod = 1',
            [$chatId]
        );
    }

    private function monthRange(?string $month, string $timezone): array
    {
        $tz = new DateTimeZone($timezone);
        if ($month) {
            $startLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00', $tz);
            if ($startLocal === false) {
                $startLocal = new DateTimeImmutable('first day of this month 00:00:00', $tz);
            }
        } else {
            $startLocal = new DateTimeImmutable('first day of this month 00:00:00', $tz);
        }

        $endLocal = $startLocal->modify('first day of next month');
        $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'));
        $endUtc = $endLocal->setTimezone(new DateTimeZone('UTC'));

        return [
            'start_local' => $startLocal,
            'end_local' => $endLocal,
            'start_utc' => $startUtc->format('Y-m-d H:i:s'),
            'end_utc' => $endUtc->format('Y-m-d H:i:s'),
            'label' => $startLocal->format('F Y'),
            'month' => $startLocal->format('Y-m'),
        ];
    }

    private function computeActiveMinutes(array $timestamps, int $gap, int $floor): float
    {
        if (empty($timestamps)) {
            return 0.0;
        }

        sort($timestamps);
        $active = 0.0;
        $prev = null;

        foreach ($timestamps as $ts) {
            if ($floor > 0) {
                $active += $floor;
            }
            if ($prev !== null) {
                $diff = ($ts - $prev) / 60;
                if ($diff > 0) {
                    $active += min($diff, $gap);
                }
            }
            $prev = $ts;
        }

        return round($active, 2);
    }

    private function computeDaysActive(array $timestamps, string $timezone): int
    {
        if (empty($timestamps)) {
            return 0;
        }
        $tz = new DateTimeZone($timezone);
        $days = [];
        foreach ($timestamps as $ts) {
            $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
            $days[$dt->format('Y-m-d')] = true;
        }
        return count($days);
    }

    private function computePeakHour(array $timestamps, string $timezone): string
    {
        if (empty($timestamps)) {
            return 'N/A';
        }
        $tz = new DateTimeZone($timezone);
        $hours = array_fill(0, 24, 0);
        foreach ($timestamps as $ts) {
            $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
            $hour = (int)$dt->format('G');
            $hours[$hour]++;
        }
        $peak = array_keys($hours, max($hours), true);
        $hour = $peak[0] ?? 0;
        return sprintf('%02d:00', $hour);
    }

    private function computeMembershipMinutes(array $rows, array $range): float
    {
        if (empty($rows)) {
            return 0.0;
        }

        $start = $this->toTimestampUtc($range['start_utc']);
        $end = $this->toTimestampUtc($range['end_utc']);
        $total = 0.0;

        foreach ($rows as $row) {
            $joined = $this->toTimestampUtc($row['joined_at']);
            $left = $row['left_at'] ? $this->toTimestampUtc($row['left_at']) : $end;

            $periodStart = max($joined, $start);
            $periodEnd = min($left, $end);
            if ($periodEnd > $periodStart) {
                $total += ($periodEnd - $periodStart) / 60;
            }
        }

        return round($total, 2);
    }

    private function computeScore(array $metrics, array $weights): float
    {
        $score = 0.0;
        $score += ($metrics['messages'] ?? 0) * ($weights['message'] ?? 1.0);
        $score += ($metrics['warnings'] ?? 0) * ($weights['warn'] ?? 1.0);
        $score += ($metrics['mutes'] ?? 0) * ($weights['mute'] ?? 1.0);
        $score += ($metrics['bans'] ?? 0) * ($weights['ban'] ?? 1.0);
        $score += ($metrics['active_minutes'] ?? 0) * ($weights['active_minute'] ?? 0.0);
        $score += ($metrics['membership_minutes'] ?? 0) * ($weights['membership_minute'] ?? 0.0);
        $score += ($metrics['days_active'] ?? 0) * ($weights['day_active'] ?? 0.0);
        return round($score, 2);
    }

    private function buildSummary(array $stats): array
    {
        $summary = [
            'total_mods' => count($stats),
            'messages' => 0,
            'warnings' => 0,
            'mutes' => 0,
            'bans' => 0,
            'active_minutes' => 0,
            'membership_minutes' => 0,
            'avg_score' => 0,
        ];

        $scoreTotal = 0.0;
        foreach ($stats as $mod) {
            $summary['messages'] += $mod['messages'];
            $summary['warnings'] += $mod['warnings'];
            $summary['mutes'] += $mod['mutes'];
            $summary['bans'] += $mod['bans'];
            $summary['active_minutes'] += $mod['active_minutes'];
            $summary['membership_minutes'] += $mod['membership_minutes'];
            $scoreTotal += $mod['score'];
        }

        $summary['avg_score'] = $summary['total_mods'] > 0 ? round($scoreTotal / $summary['total_mods'], 2) : 0;

        return $summary;
    }

    private function displayName(array $user): string
    {
        if (!empty($user['username'])) {
            return '@' . $user['username'];
        }
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        return $name !== '' ? $name : 'User ' . $user['user_id'];
    }

    private function toTimestampUtc(string $datetime): int
    {
        $dt = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
        return $dt->getTimestamp();
    }
}
