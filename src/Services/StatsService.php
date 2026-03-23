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

        $trendMap = [];
        if (!empty($range['start_local']) && $range['start_local'] instanceof DateTimeImmutable) {
            $trendScores = [];
            $trendCounts = [];
            for ($i = 1; $i <= 3; $i++) {
                $trendStart = $range['start_local']->modify('-' . $i . ' month');
                $trendEnd = $trendStart->modify('first day of next month');
                $trendRange = $this->customRange($trendStart, $trendEnd, $trendStart->format('F Y'));
                $trendStats = $this->buildStats($chatId, $mods, $trendRange, $settings, $timezone);
                foreach ($trendStats as $row) {
                    $uid = $row['user_id'];
                    $trendScores[$uid] = ($trendScores[$uid] ?? 0) + ($row['score'] ?? 0);
                    $trendCounts[$uid] = ($trendCounts[$uid] ?? 0) + 1;
                }
            }
            foreach ($trendScores as $uid => $sum) {
                $count = $trendCounts[$uid] ?? 0;
                if ($count > 0) {
                    $trendMap[$uid] = $sum / $count;
                }
            }
        }

        foreach ($stats as &$modStat) {
            $base = $trendMap[$modStat['user_id']] ?? 0.0;
            if ($base > 0) {
                $delta = (($modStat['score'] - $base) / $base) * 100.0;
                $modStat['trend_3m'] = $delta;
                if ($delta >= 5) {
                    $modStat['trend_3m_dir'] = 'up';
                } elseif ($delta <= -5) {
                    $modStat['trend_3m_dir'] = 'down';
                } else {
                    $modStat['trend_3m_dir'] = 'flat';
                }
            } else {
                $modStat['trend_3m'] = null;
                $modStat['trend_3m_dir'] = 'flat';
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

    public function getMonthlyStatsForChats(array $chatIds, ?string $month = null): array
    {
        $chatIds = array_values(array_filter($chatIds, static fn($id) => $id !== null && $id !== ''));
        if (empty($chatIds)) {
            return [
                'mods' => [],
                'range' => $this->monthRange($month, $this->config['timezone'] ?? 'UTC'),
                'timezone' => $this->config['timezone'] ?? 'UTC',
                'summary' => [],
                'chats' => [],
            ];
        }

        $titles = $this->getChatTitles($chatIds);
        $aggregate = [];
        $chatSummaries = [];
        $range = null;
        $timezone = $this->config['timezone'] ?? 'UTC';

        foreach ($chatIds as $chatId) {
            $bundle = $this->getMonthlyStats($chatId, $month);
            if ($range === null) {
                $range = $bundle['range'];
                $timezone = $bundle['timezone'];
            }

            $mods = $bundle['mods'];
            $topMod = $mods[0] ?? null;

            $chatSummaries[] = [
                'chat_id' => $chatId,
                'title' => $titles[$chatId] ?? ('Chat ' . $chatId),
                'summary' => $bundle['summary'],
                'top_mod' => $topMod,
                'budget' => (float)($bundle['settings']['reward_budget'] ?? 0),
            ];

            foreach ($mods as $mod) {
                $userId = (int)$mod['user_id'];
                if (!isset($aggregate[$userId])) {
                    $aggregate[$userId] = [
                        'user_id' => $userId,
                        'username' => $mod['username'] ?? null,
                        'first_name' => $mod['first_name'] ?? null,
                        'last_name' => $mod['last_name'] ?? null,
                        'display_name' => $mod['display_name'] ?? $this->displayName($mod),
                        'role' => null,
                        'role_multiplier' => 1.0,
                        'messages' => 0,
                        'internal_messages' => 0,
                        'external_messages' => 0,
                        'warnings' => 0,
                        'mutes' => 0,
                        'bans' => 0,
                        'actions_total' => 0,
                        'active_minutes' => 0,
                        'internal_active_minutes' => 0,
                        'external_active_minutes' => 0,
                        'membership_minutes' => 0,
                        'days_active' => 0,
                        'peak_hour' => $mod['peak_hour'] ?? '',
                        'score' => 0,
                        'improvement' => null,
                        'impact_score' => 0,
                        'consistency_index' => 0,
                        'last_active_at' => null,
                        'trend_3m' => null,
                        'trend_3m_dir' => 'flat',
                    ];
                }

                $aggregate[$userId]['messages'] += (int)($mod['messages'] ?? 0);
                $aggregate[$userId]['internal_messages'] += (int)($mod['internal_messages'] ?? 0);
                $aggregate[$userId]['external_messages'] += (int)($mod['external_messages'] ?? 0);
                $aggregate[$userId]['warnings'] += (int)($mod['warnings'] ?? 0);
                $aggregate[$userId]['mutes'] += (int)($mod['mutes'] ?? 0);
                $aggregate[$userId]['bans'] += (int)($mod['bans'] ?? 0);
                $aggregate[$userId]['active_minutes'] += (float)($mod['active_minutes'] ?? 0);
                $aggregate[$userId]['internal_active_minutes'] += (float)($mod['internal_active_minutes'] ?? 0);
                $aggregate[$userId]['external_active_minutes'] += (float)($mod['external_active_minutes'] ?? 0);
                $aggregate[$userId]['membership_minutes'] += (float)($mod['membership_minutes'] ?? 0);
                $aggregate[$userId]['days_active'] += (int)($mod['days_active'] ?? 0);
                $incomingLast = $mod['last_active_at'] ?? null;
                if ($incomingLast) {
                    $currentLast = $aggregate[$userId]['last_active_at'];
                    if ($currentLast === null || strtotime($incomingLast) > strtotime((string)$currentLast)) {
                        $aggregate[$userId]['last_active_at'] = $incomingLast;
                    }
                }
            }
        }

        $weights = $this->config['score_weights'] ?? [];
        $impactWeights = $this->config['impact_weights'] ?? [];
        $totalDays = 1;
        if ($range && isset($range['start_local'], $range['end_local']) && $range['start_local'] instanceof DateTimeImmutable && $range['end_local'] instanceof DateTimeImmutable) {
            $totalDays = max(1, (int)$range['start_local']->diff($range['end_local'])->days);
        }
        foreach ($aggregate as &$mod) {
            $mod['actions_total'] = $mod['warnings'] + $mod['mutes'] + $mod['bans'];
            $mod['score'] = $this->computeScore([
                'messages' => $mod['messages'],
                'warnings' => $mod['warnings'],
                'mutes' => $mod['mutes'],
                'bans' => $mod['bans'],
                'active_minutes' => $mod['active_minutes'],
                'membership_minutes' => $mod['membership_minutes'],
                'days_active' => $mod['days_active'],
            ], $weights);
            $mod['impact_score'] = $this->computeImpactScore([
                'messages' => $mod['messages'],
                'warnings' => $mod['warnings'],
                'mutes' => $mod['mutes'],
                'bans' => $mod['bans'],
                'active_minutes' => $mod['active_minutes'],
                'last_active_at' => $mod['last_active_at'],
                'range_end' => $range['end_utc'] ?? null,
            ], $impactWeights);
            $mod['consistency_index'] = $totalDays > 0 ? min(100, round(($mod['days_active'] / $totalDays) * 100, 1)) : 0;
        }
        unset($mod);

        $mods = array_values($aggregate);
        usort($mods, fn($a, $b) => $b['score'] <=> $a['score']);

        $summary = $this->buildSummary($mods);

        return [
            'mods' => $mods,
            'range' => $range ?? $this->monthRange($month, $timezone),
            'timezone' => $timezone,
            'summary' => $summary,
            'chats' => $chatSummaries,
        ];
    }

    public function getTimesheet(int|string $chatId, int|string $userId, DateTimeImmutable $startLocal, DateTimeImmutable $endLocal): array
    {
        $settings = $this->settings->get($chatId);
        $timezone = $settings['timezone'] ?? ($this->config['timezone'] ?? 'UTC');
        $tz = new DateTimeZone($timezone);
        $gap = (int)($settings['active_gap_minutes'] ?? $this->config['active_gap_minutes'] ?? 5);
        $floor = (int)($settings['active_floor_minutes'] ?? $this->config['active_floor_minutes'] ?? 1);

        $startLocal = $startLocal->setTimezone($tz)->setTime(0, 0, 0);
        $endLocal = $endLocal->setTimezone($tz)->setTime(0, 0, 0);
        if ($endLocal < $startLocal) {
            [$startLocal, $endLocal] = [$endLocal, $startLocal];
        }
        $endExclusiveLocal = $endLocal->modify('+1 day');

        $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $endUtc = $endExclusiveLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $rows = $this->db->fetchAll(
            'SELECT sent_at FROM messages WHERE chat_id = ? AND user_id = ? AND sent_at >= ? AND sent_at < ? ORDER BY sent_at ASC',
            [$chatId, $userId, $startUtc, $endUtc]
        );

        $timestampsByDate = [];
        foreach ($rows as $row) {
            $dt = new DateTimeImmutable($row['sent_at'], new DateTimeZone('UTC'));
            $dtLocal = $dt->setTimezone($tz);
            $dateKey = $dtLocal->format('Y-m-d');
            $timestampsByDate[$dateKey][] = $dt->getTimestamp();
        }

        $memberships = $this->db->fetchAll(
            'SELECT joined_at, left_at FROM memberships WHERE chat_id = ? AND user_id = ? AND joined_at < ? AND (left_at IS NULL OR left_at > ?)',
            [$chatId, $userId, $endUtc, $startUtc]
        );

        $days = [];
        $cursor = $startLocal;
        while ($cursor < $endExclusiveLocal) {
            $key = $cursor->format('Y-m-d');
            $timestamps = $timestampsByDate[$key] ?? [];
            $messageCount = count($timestamps);
            $activeMinutes = $this->computeActiveMinutes($timestamps, $gap, $floor);
            $presenceMinutes = $this->computeMembershipMinutesForDay($memberships, $cursor, $cursor->modify('+1 day'));

            $days[] = [
                'date' => $key,
                'messages' => $messageCount,
                'active_minutes' => $activeMinutes,
                'presence_minutes' => $presenceMinutes,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $totalMessages = 0;
        $totalActive = 0.0;
        $totalPresence = 0.0;
        foreach ($days as $day) {
            $totalMessages += (int)$day['messages'];
            $totalActive += (float)$day['active_minutes'];
            $totalPresence += (float)$day['presence_minutes'];
        }

        $label = $startLocal->format('Y-m-d') . ' to ' . $endLocal->format('Y-m-d');

        return [
            'timezone' => $timezone,
            'range' => [
                'start_local' => $startLocal,
                'end_local' => $endLocal,
                'label' => $label,
            ],
            'gap_minutes' => $gap,
            'floor_minutes' => $floor,
            'days' => $days,
            'totals' => [
                'messages' => $totalMessages,
                'active_minutes' => $totalActive,
                'presence_minutes' => $totalPresence,
            ],
        ];
    }

    public function getMonthToDateStats(int|string $chatId, ?DateTimeImmutable $nowLocal = null): array
    {
        $settings = $this->settings->get($chatId);
        $timezone = $settings['timezone'] ?? ($this->config['timezone'] ?? 'UTC');

        $tz = new DateTimeZone($timezone);
        $nowLocal = $nowLocal ?: new DateTimeImmutable('now', $tz);
        $startLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $nowLocal->format('Y-m-01 00:00:00'), $tz);
        if ($startLocal === false) {
            $startLocal = new DateTimeImmutable('first day of this month 00:00:00', $tz);
        }

        $label = $startLocal->format('F Y') . ' (MTD)';
        $range = $this->customRange($startLocal, $nowLocal, $label);

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

        $prevStart = $startLocal->modify('-1 month');
        $prevEnd = $nowLocal->modify('-1 month');
        $prevRange = $this->customRange($prevStart, $prevEnd, $label);
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

    public function getRollingStats(int|string $chatId, int $days = 7, ?DateTimeImmutable $nowLocal = null): array
    {
        $settings = $this->settings->get($chatId);
        $timezone = $settings['timezone'] ?? ($this->config['timezone'] ?? 'UTC');

        $tz = new DateTimeZone($timezone);
        $nowLocal = $nowLocal ?: new DateTimeImmutable('now', $tz);
        $days = max(1, (int)$days);
        $startLocal = $nowLocal->modify('-' . ($days - 1) . ' days')->setTime(0, 0, 0);
        $label = 'Last ' . $days . ' days';
        $range = $this->customRange($startLocal, $nowLocal, $label);

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
        $roleMap = $this->getRoleMap($chatId);
        $totalDays = 1;
        if (!empty($range['start_local']) && !empty($range['end_local']) && $range['start_local'] instanceof DateTimeImmutable && $range['end_local'] instanceof DateTimeImmutable) {
            $totalDays = max(1, (int)$range['start_local']->diff($range['end_local'])->days);
        }
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
        $impactWeights = $this->config['impact_weights'] ?? [];
        $gap = (int)($settings['active_gap_minutes'] ?? $this->config['active_gap_minutes'] ?? 5);
        $floor = (int)($settings['active_floor_minutes'] ?? $this->config['active_floor_minutes'] ?? 1);

        $stats = [];
        foreach ($mods as $mod) {
            $userId = (int)$mod['user_id'];
            $role = $roleMap[$userId]['role'] ?? null;
            $roleMultiplier = $this->getRoleMultiplier($role);
            $timestamps = $messagesByUser[$userId] ?? [];
            $lastActiveAt = null;
            if (!empty($timestamps)) {
                $lastActiveAt = gmdate('Y-m-d H:i:s', max($timestamps));
            }

            $internalMessageCount = count($timestamps);
            $externalMessageCount = (int)($externalStats[$userId]['messages'] ?? 0);
            $messageCount = $internalMessageCount + $externalMessageCount;
            $internalActiveMinutes = $this->computeActiveMinutes($timestamps, $gap, $floor);
            $activeMinutes = $internalActiveMinutes;
            $externalActiveMinutes = (int)($externalStats[$userId]['active_minutes'] ?? 0);
            $activeMinutes += $externalActiveMinutes;
            $daysActive = $this->computeDaysActive($timestamps, $timezone);
            $peakHour = $this->computePeakHour($timestamps, $timezone);
            $membershipMinutes = $this->computeMembershipMinutes($membershipsByUser[$userId] ?? [], $range);

            $actions = $actionsByUser[$userId] ?? [];
            $warnings = $actions['warn'] ?? 0;
            $mutes = $actions['mute'] ?? 0;
            $bans = $actions['ban'] ?? 0;
            $warnings += (int)($externalStats[$userId]['warnings'] ?? 0);
            $mutes += (int)($externalStats[$userId]['mutes'] ?? 0);
            $bans += (int)($externalStats[$userId]['bans'] ?? 0);
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
            if ($roleMultiplier !== 1.0) {
                $score = round($score * $roleMultiplier, 2);
            }

            $impactScore = $this->computeImpactScore([
                'messages' => $messageCount,
                'warnings' => $warnings,
                'mutes' => $mutes,
                'bans' => $bans,
                'active_minutes' => $activeMinutes,
                'last_active_at' => $lastActiveAt,
                'range_end' => $range['end_utc'],
            ], $impactWeights);
            if ($roleMultiplier !== 1.0) {
                $impactScore = round($impactScore * $roleMultiplier, 2);
            }

            $consistency = round(($daysActive / $totalDays) * 100, 1);

            $stats[] = [
                'user_id' => $userId,
                'username' => $mod['username'],
                'first_name' => $mod['first_name'],
                'last_name' => $mod['last_name'],
                'display_name' => $this->displayName($mod),
                'role' => $role,
                'role_multiplier' => $roleMultiplier,
                'messages' => $messageCount,
                'internal_messages' => $internalMessageCount,
                'external_messages' => $externalMessageCount,
                'internal_active_minutes' => $internalActiveMinutes,
                'external_active_minutes' => $externalActiveMinutes,
                'warnings' => $warnings,
                'mutes' => $mutes,
                'bans' => $bans,
                'actions_total' => $actionsTotal,
                'active_minutes' => $activeMinutes,
                'membership_minutes' => $membershipMinutes,
                'days_active' => $daysActive,
                'consistency_index' => $consistency,
                'peak_hour' => $peakHour,
                'last_active_at' => $lastActiveAt,
                'score' => $score,
                'impact_score' => $impactScore,
            ];
        }

        usort($stats, fn($a, $b) => $b['score'] <=> $a['score']);

        return $stats;
    }

    public function getHourlyCoverage(int|string $chatId, array $range, string $timezone, ?array $modIds = null): array
    {
        $hours = array_fill(0, 24, 0);
        if ($modIds === null) {
            $mods = $this->getMods($chatId);
            $modIds = array_map(fn($mod) => (int)$mod['user_id'], $mods);
        }
        if (empty($modIds)) {
            return $hours;
        }

        $modPlaceholders = implode(',', array_fill(0, count($modIds), '?'));
        $rows = $this->db->fetchAll(
            'SELECT sent_at FROM messages WHERE chat_id = ? AND sent_at >= ? AND sent_at < ? AND user_id IN (' . $modPlaceholders . ')',
            array_merge([$chatId, $range['start_utc'], $range['end_utc']], $modIds)
        );

        $tz = new DateTimeZone($timezone);
        foreach ($rows as $row) {
            $dt = new DateTimeImmutable($row['sent_at'], new DateTimeZone('UTC'));
            $dt = $dt->setTimezone($tz);
            $hour = (int)$dt->format('G');
            $hours[$hour]++;
        }

        return $hours;
    }

    public function getCoverageHeatmap(int|string $chatId, array $range, string $timezone, int $minMods = 1): array
    {
        $minMods = max(1, (int)$minMods);
        $messageGrid = array_fill(0, 7, array_fill(0, 24, 0));
        $modSets = array_fill(0, 7, array_fill(0, 24, []));

        $mods = $this->getMods($chatId);
        if (empty($mods)) {
            return [
                'messages' => $messageGrid,
                'mods' => array_fill(0, 7, array_fill(0, 24, 0)),
                'coverage_pct' => 0.0,
                'max_mods' => 0,
                'gaps' => [],
            ];
        }

        $modIds = array_map(fn($mod) => (int)$mod['user_id'], $mods);
        $modPlaceholders = implode(',', array_fill(0, count($modIds), '?'));
        $rows = $this->db->fetchAll(
            'SELECT user_id, sent_at FROM messages WHERE chat_id = ? AND sent_at >= ? AND sent_at < ? AND user_id IN (' . $modPlaceholders . ')',
            array_merge([$chatId, $range['start_utc'], $range['end_utc']], $modIds)
        );

        $tz = new DateTimeZone($timezone);
        foreach ($rows as $row) {
            $dt = new DateTimeImmutable($row['sent_at'], new DateTimeZone('UTC'));
            $dt = $dt->setTimezone($tz);
            $dow = (int)$dt->format('N') - 1; // 0=Mon
            $hour = (int)$dt->format('G');
            $messageGrid[$dow][$hour]++;
            $modSets[$dow][$hour][(int)$row['user_id']] = true;
        }

        $modGrid = array_fill(0, 7, array_fill(0, 24, 0));
        $maxMods = 0;
        $gaps = [];
        $covered = 0;
        $totalSlots = 7 * 24;

        for ($d = 0; $d < 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $count = count($modSets[$d][$h]);
                $modGrid[$d][$h] = $count;
                if ($count > $maxMods) {
                    $maxMods = $count;
                }
                if ($count >= $minMods) {
                    $covered++;
                } else {
                    $gaps[] = [
                        'day' => $d,
                        'hour' => $h,
                        'mods' => $count,
                        'messages' => $messageGrid[$d][$h],
                    ];
                }
            }
        }

        usort($gaps, fn($a, $b) => ($a['mods'] <=> $b['mods']) ?: ($a['messages'] <=> $b['messages']));
        $coveragePct = $totalSlots > 0 ? round(($covered / $totalSlots) * 100, 1) : 0.0;

        return [
            'messages' => $messageGrid,
            'mods' => $modGrid,
            'coverage_pct' => $coveragePct,
            'max_mods' => $maxMods,
            'gaps' => $gaps,
        ];
    }

    public function getActionRiskStats(int|string $chatId, array $range, array $modIds, int $newUserDays = 7): array
    {
        $modIds = array_values(array_filter(array_map('intval', $modIds)));
        if (empty($modIds)) {
            return [];
        }

        $newUserDays = max(1, (int)$newUserDays);
        $modPlaceholders = implode(',', array_fill(0, count($modIds), '?'));

        $rows = $this->db->fetchAll(
            'SELECT a.mod_id,
                    COUNT(*) as total_actions,
                    SUM(CASE
                        WHEN fj.first_join IS NOT NULL
                             AND fj.first_join <= a.created_at
                             AND TIMESTAMPDIFF(DAY, fj.first_join, a.created_at) <= ?
                        THEN 1 ELSE 0 END) AS new_user_actions
             FROM actions a
             LEFT JOIN (
                SELECT user_id, MIN(joined_at) as first_join
                FROM memberships
                WHERE chat_id = ?
                GROUP BY user_id
             ) fj ON fj.user_id = a.target_user_id
             WHERE a.chat_id = ?
               AND a.created_at >= ?
               AND a.created_at < ?
               AND a.mod_id IN (' . $modPlaceholders . ')
             GROUP BY a.mod_id',
            array_merge([$newUserDays, $chatId, $chatId, $range['start_utc'], $range['end_utc']], $modIds)
        );

        $map = [];
        foreach ($rows as $row) {
            $modId = (int)$row['mod_id'];
            $map[$modId] = [
                'total_actions' => (int)($row['total_actions'] ?? 0),
                'new_user_actions' => (int)($row['new_user_actions'] ?? 0),
            ];
        }

        return $map;
    }

    private function getExternalStatsMap(int|string $chatId, string $month): array
    {
        $rows = $this->db->fetchAll(
            'SELECT user_id,
                    SUM(messages) AS messages,
                    SUM(replies) AS replies,
                    SUM(reputation_take) AS reputation_take,
                    SUM(warnings) AS warnings,
                    SUM(mutes) AS mutes,
                    SUM(bans) AS bans,
                    SUM(active_minutes) AS active_minutes
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
                'warnings' => (int)($row['warnings'] ?? 0),
                'mutes' => (int)($row['mutes'] ?? 0),
                'bans' => (int)($row['bans'] ?? 0),
                'active_minutes' => (int)($row['active_minutes'] ?? 0),
            ];
        }

        return $map;
    }

    private function getRoleMap(int|string $chatId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT user_id, role FROM mod_roster WHERE chat_id = ?',
            [$chatId]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['user_id']] = [
                'role' => $row['role'] ?? null,
            ];
        }
        return $map;
    }

    private function getRoleMultiplier(?string $role): float
    {
        if ($role === null || trim($role) === '') {
            return 1.0;
        }
        $roleKey = strtolower(trim($role));
        $map = $this->config['role_multipliers'] ?? [];
        if (isset($map[$roleKey])) {
            return (float)$map[$roleKey];
        }
        foreach ($map as $key => $value) {
            $key = strtolower(trim((string)$key));
            if ($key !== '' && strpos($roleKey, $key) !== false) {
                return (float)$value;
            }
        }
        return 1.0;
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

    private function customRange(DateTimeImmutable $startLocal, DateTimeImmutable $endLocal, string $label): array
    {
        $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'));
        $endUtc = $endLocal->setTimezone(new DateTimeZone('UTC'));

        return [
            'start_local' => $startLocal,
            'end_local' => $endLocal,
            'start_utc' => $startUtc->format('Y-m-d H:i:s'),
            'end_utc' => $endUtc->format('Y-m-d H:i:s'),
            'label' => $label,
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

    private function computeMembershipMinutesForDay(array $rows, DateTimeImmutable $dayStartLocal, DateTimeImmutable $dayEndLocal): float
    {
        if (empty($rows)) {
            return 0.0;
        }

        $dayStartUtc = $dayStartLocal->setTimezone(new DateTimeZone('UTC'));
        $dayEndUtc = $dayEndLocal->setTimezone(new DateTimeZone('UTC'));
        $dayStartTs = $dayStartUtc->getTimestamp();
        $dayEndTs = $dayEndUtc->getTimestamp();
        $minutes = 0.0;

        foreach ($rows as $row) {
            $joined = new DateTimeImmutable($row['joined_at'], new DateTimeZone('UTC'));
            $left = $row['left_at'] ? new DateTimeImmutable($row['left_at'], new DateTimeZone('UTC')) : null;
            $startTs = max($joined->getTimestamp(), $dayStartTs);
            $endTs = $left ? min($left->getTimestamp(), $dayEndTs) : $dayEndTs;
            if ($endTs > $startTs) {
                $minutes += ($endTs - $startTs) / 60;
            }
        }

        return round($minutes, 2);
    }

    private function computeImpactScore(array $metrics, array $weights): float
    {
        $messages = (int)($metrics['messages'] ?? 0);
        $warnings = (int)($metrics['warnings'] ?? 0);
        $mutes = (int)($metrics['mutes'] ?? 0);
        $bans = (int)($metrics['bans'] ?? 0);
        $activeMinutes = (float)($metrics['active_minutes'] ?? 0);

        $impact = 0.0;
        $impact += log(1 + $messages) * ($weights['message'] ?? 0.2);
        $impact += $warnings * ($weights['warn'] ?? 2.0);
        $impact += $mutes * ($weights['mute'] ?? 5.0);
        $impact += $bans * ($weights['ban'] ?? 8.0);
        $impact += sqrt($activeMinutes) * ($weights['active_minute'] ?? 0.05);

        $recencyDays = (int)($this->config['impact_recency_days'] ?? 0);
        $recencyBoost = (float)($this->config['impact_recency_multiplier'] ?? 1.0);
        $lastActiveAt = $metrics['last_active_at'] ?? null;
        $rangeEnd = $metrics['range_end'] ?? null;
        if ($recencyDays > 0 && $recencyBoost > 1.0 && $lastActiveAt && $rangeEnd) {
            $last = strtotime((string)$lastActiveAt);
            $end = strtotime((string)$rangeEnd);
            if ($last && $end) {
                $daysSince = ($end - $last) / 86400;
                if ($daysSince <= $recencyDays) {
                    $impact *= $recencyBoost;
                }
            }
        }

        return round($impact, 2);
    }

    private function computeScore(array $metrics, array $weights): float
    {
        $rules = $this->config['score_rules'] ?? [];

        $messages = (int)($metrics['messages'] ?? 0);
        $warnings = (int)($metrics['warnings'] ?? 0);
        $mutes = (int)($metrics['mutes'] ?? 0);
        $bans = (int)($metrics['bans'] ?? 0);
        $activeMinutes = (float)($metrics['active_minutes'] ?? 0);
        $membershipMinutes = (float)($metrics['membership_minutes'] ?? 0);
        $daysActive = (int)($metrics['days_active'] ?? 0);

        if (isset($rules['message_cap'])) {
            $messages = min($messages, (int)$rules['message_cap']);
        }
        if (isset($rules['active_minutes_cap'])) {
            $activeMinutes = min($activeMinutes, (int)$rules['active_minutes_cap']);
        }
        if (isset($rules['membership_minutes_cap'])) {
            $membershipMinutes = min($membershipMinutes, (int)$rules['membership_minutes_cap']);
        }

        $normalizeByDays = !empty($rules['normalize_by_days']);
        if ($normalizeByDays) {
            $dayDivisor = max(1, $daysActive);
            $messages = $messages / $dayDivisor;
            $activeMinutes = $activeMinutes / $dayDivisor;
        }

        $score = 0.0;
        $score += log(1 + $messages) * ($weights['message'] ?? 1.0);
        $score += $warnings * ($weights['warn'] ?? 1.0);
        $score += $mutes * ($weights['mute'] ?? 1.0);
        $score += $bans * ($weights['ban'] ?? 1.0);
        $score += sqrt($activeMinutes) * ($weights['active_minute'] ?? 0.0);
        $score += sqrt($membershipMinutes) * ($weights['membership_minute'] ?? 0.0);
        $score += $daysActive * ($weights['day_active'] ?? 0.0);

        $minDaysForFull = (int)($rules['min_days_for_full'] ?? 0);
        if ($minDaysForFull > 0) {
            $dayFactor = min(1.0, $daysActive / $minDaysForFull);
            $score *= (0.5 + (0.5 * $dayFactor));
        }

        return round($score, 2);
    }

    private function buildSummary(array $stats): array
    {
        $summary = [
            'total_mods' => count($stats),
            'messages' => 0,
            'internal_messages' => 0,
            'external_messages' => 0,
            'warnings' => 0,
            'mutes' => 0,
            'bans' => 0,
            'active_minutes' => 0,
            'internal_active_minutes' => 0,
            'external_active_minutes' => 0,
            'membership_minutes' => 0,
            'avg_score' => 0,
            'avg_impact' => 0,
            'avg_consistency' => 0,
        ];

        $scoreTotal = 0.0;
        $impactTotal = 0.0;
        $consistencyTotal = 0.0;
        foreach ($stats as $mod) {
            $summary['messages'] += $mod['messages'];
            $summary['internal_messages'] += $mod['internal_messages'] ?? 0;
            $summary['external_messages'] += $mod['external_messages'] ?? 0;
            $summary['warnings'] += $mod['warnings'];
            $summary['mutes'] += $mod['mutes'];
            $summary['bans'] += $mod['bans'];
            $summary['active_minutes'] += $mod['active_minutes'];
            $summary['internal_active_minutes'] += $mod['internal_active_minutes'] ?? 0;
            $summary['external_active_minutes'] += $mod['external_active_minutes'] ?? 0;
            $summary['membership_minutes'] += $mod['membership_minutes'];
            $scoreTotal += $mod['score'];
            $impactTotal += $mod['impact_score'] ?? 0;
            $consistencyTotal += $mod['consistency_index'] ?? 0;
        }

        $summary['avg_score'] = $summary['total_mods'] > 0 ? round($scoreTotal / $summary['total_mods'], 2) : 0;
        $summary['avg_impact'] = $summary['total_mods'] > 0 ? round($impactTotal / $summary['total_mods'], 2) : 0;
        $summary['avg_consistency'] = $summary['total_mods'] > 0 ? round($consistencyTotal / $summary['total_mods'], 1) : 0;

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

    private function getChatTitles(array $chatIds): array
    {
        if (empty($chatIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($chatIds), '?'));
        $rows = $this->db->fetchAll(
            'SELECT id, title FROM chats WHERE id IN (' . $placeholders . ')',
            $chatIds
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row['id']] = $row['title'] ?: ('Chat ' . $row['id']);
        }
        return $map;
    }
}
