<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Telegram;
use App\Services\SettingsService;
use App\Services\StatsService;
use App\Services\RewardService;
use App\Services\RewardContextService;
use App\Services\RewardHistoryService;
use App\Services\ArchiveService;
use App\Services\SubscriptionService;
use App\Services\NotificationService;
use App\Services\PerformanceReviewService;
use App\Services\RetentionRiskService;
use App\Services\AuditLogService;
use App\Services\ReportChannelService;
use App\Reports\RewardSheet;
use App\Logger;
use App\Services\ChangelogService;

$token = $config['bot_token'] ?? null;
if (!$token || $token === 'YOUR_TELEGRAM_BOT_TOKEN') {
    echo "Missing bot token in config.php\n";
    exit(1);
}

$db = new Database($config['db']);
$tg = new Telegram($token, $config['telegram'] ?? []);
Logger::initChannel($tg, $config);
$reporter = new ReportChannelService($tg, $config);
$changelog = new ChangelogService();
$changelog->sendIfUpdated($tg, $config, 'scheduler');
$settingsService = new SettingsService($db, $config);
$statsService = new StatsService($db, $settingsService, $config);
$rewardService = new RewardService($config);
$rewardService->setAuditLogger(new AuditLogService($db));
$rewardContext = new RewardContextService($db, $config);
$rewardHistory = new RewardHistoryService($db);
$archive = new ArchiveService($db);
$subscriptions = new SubscriptionService($db, $config);
$notifications = new NotificationService($db);
$performanceReview = new PerformanceReviewService($statsService, $settingsService, $config);
$retentionRisk = new RetentionRiskService($statsService, $settingsService, $config);
$rewardSheet = new RewardSheet($statsService, $rewardService, $config, $rewardContext, $rewardHistory, $archive);

function percentDrop(float $current, float $previous): ?float
{
    if ($previous <= 0) {
        return null;
    }
    $drop = (($previous - $current) / $previous) * 100;
    if ($drop <= 0) {
        return 0.0;
    }
    return round($drop, 1);
}

function buildReportMeta(array $config, ?int $chatId, string $chatTitle, string $reportType, ?string $period = null, ?float $budget = null, ?string $timezone = null): array
{
    $timezone = $timezone ?: ($config['timezone'] ?? 'UTC');
    return [
        'report_type' => $reportType,
        'chat_id' => $chatId,
        'chat_title' => $chatTitle,
        'period' => $period,
        'budget' => $budget,
        'timezone' => $timezone,
    ];
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function buildWeeklyTldr(array $stats, string $chatTitle, int $days): string
{
    $summary = $stats['summary'] ?? [];
    $mods = $stats['mods'] ?? [];

    $messages = number_format((int)($summary['messages'] ?? 0));
    $activeHours = number_format(((float)($summary['active_minutes'] ?? 0)) / 60, 1);
    $actions = number_format((int)($summary['warnings'] ?? 0) + (int)($summary['mutes'] ?? 0) + (int)($summary['bans'] ?? 0));

    $topRewardLabel = 'n/a';
    if (!empty($mods)) {
        $top = $mods[0];
        $topName = escapeHtml((string)($top['display_name'] ?? 'Unknown'));
        $topScore = number_format((float)($top['score'] ?? 0), 2);
        $topRewardLabel = $topName . ' (score ' . $topScore . ')';
    }

    $riskLabel = 'none';
    $riskReason = '';
    if (!empty($mods)) {
        $inactive = array_filter($mods, static function (array $mod): bool {
            $messages = (int)($mod['messages'] ?? 0);
            $activeMinutes = (float)($mod['active_minutes'] ?? 0);
            $actions = (int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0);
            return $messages === 0 && $activeMinutes <= 0 && $actions === 0;
        });

        if (!empty($inactive)) {
            usort($inactive, fn($a, $b) => ($b['membership_minutes'] ?? 0) <=> ($a['membership_minutes'] ?? 0));
            $riskMod = $inactive[0];
            $riskLabel = escapeHtml((string)($riskMod['display_name'] ?? 'Unknown'));
            $riskReason = '0 msgs, 0h';
        } else {
            $byScore = $mods;
            usort($byScore, fn($a, $b) => ($a['score'] ?? 0) <=> ($b['score'] ?? 0));
            $riskMod = $byScore[0] ?? null;
            if ($riskMod) {
                $riskLabel = escapeHtml((string)($riskMod['display_name'] ?? 'Unknown'));
                $riskMsgs = (int)($riskMod['messages'] ?? 0);
                $riskHoursValue = ((float)($riskMod['active_minutes'] ?? 0)) / 60;
                $riskHours = number_format($riskHoursValue, 1);
                $riskReason = $riskMsgs > 0 || $riskHoursValue > 0
                    ? $riskMsgs . ' msgs, ' . $riskHours . 'h'
                    : 'low activity';
            }
        }
    }

    $label = escapeHtml((string)($stats['range']['label'] ?? ('Last ' . $days . ' days')));
    $line = '<b>TL;DR</b> · ' . escapeHtml($chatTitle) . ' · ' . $label . ' · Quick summary: ' . $messages . ' msgs, ' . $activeHours . 'h active, ' . $actions . ' actions | Top reward: ' . $topRewardLabel . ' | Top risk: ' . $riskLabel;
    if ($riskReason !== '') {
        $line .= ' (' . $riskReason . ')';
    }

    return $line;
}

function buildPerformanceBadges(array $mods, array $config): array
{
    if (empty($mods)) {
        return [];
    }

    $weights = $config['score_weights'] ?? [];
    $fastMinMessages = 15;
    $fastMinHours = 1.0;
    $balancedMinMessages = 15;
    $balancedMinActions = 1;

    $topHelper = null;
    $topHelperMessages = -1;
    $consistencyKing = null;
    $consistencyMax = -1.0;
    $fastResponder = null;
    $fastRate = null;
    $fastFallback = null;
    $fastFallbackRate = null;
    $mostBalanced = null;
    $mostBalancedScore = null;
    $balancedFallback = null;
    $balancedFallbackScore = null;
    $mostBalancedMeta = '';
    $balancedFallbackMeta = '';

    foreach ($mods as $mod) {
        $messages = (int)($mod['messages'] ?? 0);
        if ($messages > $topHelperMessages) {
            $topHelperMessages = $messages;
            $topHelper = $mod;
        }
        $consistency = (float)($mod['consistency_index'] ?? 0);
        if ($consistency > $consistencyMax) {
            $consistencyMax = $consistency;
            $consistencyKing = $mod;
        }

        $activeMinutes = (float)($mod['active_minutes'] ?? 0);
        $hours = $activeMinutes / 60;
        if ($messages > 0 && $hours > 0) {
            $rate = $messages / $hours;
            if ($messages >= $fastMinMessages && $hours >= $fastMinHours) {
                if ($fastRate === null || $rate > $fastRate) {
                    $fastRate = $rate;
                    $fastResponder = $mod;
                }
            } else {
                if ($fastFallbackRate === null || $rate > $fastFallbackRate) {
                    $fastFallbackRate = $rate;
                    $fastFallback = $mod;
                }
            }
        }

        $warnings = (int)($mod['warnings'] ?? 0);
        $mutes = (int)($mod['mutes'] ?? 0);
        $bans = (int)($mod['bans'] ?? 0);
        $actions = $warnings + $mutes + $bans;
        $daysActive = (int)($mod['days_active'] ?? 0);
        $roleMultiplier = (float)($mod['role_multiplier'] ?? 1.0);

        $chatScore = 0.0;
        $chatScore += log(1 + $messages) * ($weights['message'] ?? 1.0);
        $chatScore += sqrt($activeMinutes) * ($weights['active_minute'] ?? 0.0);
        $chatScore += $daysActive * ($weights['day_active'] ?? 0.0);
        $chatScore *= $roleMultiplier;

        $actionScore = 0.0;
        $actionScore += $warnings * ($weights['warn'] ?? 1.0);
        $actionScore += $mutes * ($weights['mute'] ?? 1.0);
        $actionScore += $bans * ($weights['ban'] ?? 1.0);
        $actionScore *= $roleMultiplier;

        if ($chatScore > 0 && $actionScore > 0) {
            $balanceRatio = 1 - (abs($chatScore - $actionScore) / max($chatScore, $actionScore, 1.0));
            $score = ($chatScore + $actionScore) * $balanceRatio;
            $meta = 'Balance ' . number_format($balanceRatio * 100, 0) . '% · ' . $messages . ' msgs · ' . $actions . ' actions';

            if ($messages >= $balancedMinMessages && $actions >= $balancedMinActions) {
                if ($mostBalancedScore === null || $score > $mostBalancedScore) {
                    $mostBalancedScore = $score;
                    $mostBalanced = $mod;
                    $mostBalancedMeta = $meta;
                }
            } else {
                if ($balancedFallbackScore === null || $score > $balancedFallbackScore) {
                    $balancedFallbackScore = $score;
                    $balancedFallback = $mod;
                    $balancedFallbackMeta = $meta;
                }
            }
        }
    }

    if ($fastResponder === null && $fastFallback !== null) {
        $fastResponder = $fastFallback;
        $fastRate = $fastFallbackRate;
    }
    if ($mostBalanced === null && $balancedFallback !== null) {
        $mostBalanced = $balancedFallback;
        $mostBalancedMeta = $balancedFallbackMeta;
    }

    $badges = [];
    if ($topHelper) {
        $badges[] = [
            'title' => 'Top Helper',
            'name' => $topHelper['display_name'],
            'meta' => number_format((int)($topHelper['messages'] ?? 0)) . ' msgs',
        ];
    }
    if ($mostBalanced) {
        $badges[] = [
            'title' => 'Most Balanced',
            'name' => $mostBalanced['display_name'],
            'meta' => $mostBalancedMeta,
        ];
    }
    if ($consistencyKing) {
        $badges[] = [
            'title' => 'Consistency King',
            'name' => $consistencyKing['display_name'],
            'meta' => number_format((float)($consistencyKing['consistency_index'] ?? 0), 1) . '%',
        ];
    }
    if ($fastResponder && $fastRate !== null) {
        $badges[] = [
            'title' => 'Fast Responder',
            'name' => $fastResponder['display_name'],
            'meta' => number_format($fastRate, 1) . ' msgs/hr',
        ];
    }

    return $badges;
}

function buildBurnoutRisks(array $mods, array $prevMods, array $config): array
{
    if (empty($mods)) {
        return [];
    }

    $prevMap = [];
    foreach ($prevMods as $mod) {
        $prevMap[(int)$mod['user_id']] = $mod;
    }

    $riskConfig = $config['burnout_risk'] ?? [];
    $minHours = (float)($riskConfig['min_active_hours'] ?? 40);
    $consistencyDrop = (float)($riskConfig['consistency_drop'] ?? 15);
    $improvementDrop = (float)($riskConfig['improvement_drop'] ?? -10);
    $qualityPer1k = (float)($riskConfig['quality_actions_per_1k'] ?? 35);
    $qualityMinActions = (int)($riskConfig['quality_min_actions'] ?? 5);

    $risks = [];
    foreach ($mods as $mod) {
        $activeHours = ((float)($mod['active_minutes'] ?? 0)) / 60;
        if ($activeHours < $minHours) {
            continue;
        }

        $prev = $prevMap[(int)$mod['user_id']] ?? null;
        $prevConsistency = $prev ? (float)($prev['consistency_index'] ?? 0) : null;
        $consistency = (float)($mod['consistency_index'] ?? 0);
        $consistencyDropValue = $prevConsistency !== null ? ($prevConsistency - $consistency) : null;

        $improvement = $mod['improvement'] ?? null;
        $actions = (int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0);
        $messages = (int)($mod['messages'] ?? 0);
        $actionsPer1k = $messages > 0 ? ($actions / $messages) * 1000 : ($actions > 0 ? 999 : 0);
        $qualityFlag = $actions >= $qualityMinActions && $actionsPer1k >= $qualityPer1k;

        $consistencyFlag = $consistencyDropValue !== null && $consistencyDropValue >= $consistencyDrop;
        $improvementFlag = $improvement !== null && $improvement <= $improvementDrop;

        if (!$consistencyFlag && !$improvementFlag && !$qualityFlag) {
            continue;
        }

        $risks[] = [
            'name' => $mod['display_name'],
            'active_hours' => $activeHours,
            'consistency_drop' => $consistencyDropValue,
            'improvement' => $improvement,
        ];
    }

    usort($risks, fn($a, $b) => ($b['active_hours'] <=> $a['active_hours']));
    return array_slice($risks, 0, 6);
}

function buildMicroFeedbackMessage(array $mod, string $chatTitle, string $dateLabel, array $config): string
{
    $feedbackConfig = $config['micro_feedback'] ?? [];
    $minMessages = (int)($feedbackConfig['min_messages'] ?? 10);
    $minActiveHours = (float)($feedbackConfig['min_active_hours'] ?? 1.0);
    $actionsWarn = (float)($feedbackConfig['actions_per_1k_warn'] ?? 35);
    $positiveMessages = (int)($feedbackConfig['positive_messages'] ?? 50);
    $positiveHours = (float)($feedbackConfig['positive_active_hours'] ?? 2.5);

    $messages = (int)($mod['messages'] ?? 0);
    $activeHours = ((float)($mod['active_minutes'] ?? 0)) / 60;
    $actions = (int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0);
    $actionsPer1k = $messages > 0 ? ($actions / $messages) * 1000 : ($actions > 0 ? 999 : 0);

    $tips = [];
    if ($messages <= 0 && $activeHours <= 0) {
        $tips[] = 'No activity yesterday. Try to check in for 15–30 minutes.';
    } else {
        if ($activeHours < $minActiveHours) {
            $tips[] = 'Try to stay active for at least ' . number_format($minActiveHours, 1) . 'h.';
        }
        if ($messages < $minMessages) {
            $tips[] = 'Aim for ' . $minMessages . '+ messages to keep chats warm.';
        }
        if ($actions >= 3 && $actionsPer1k >= $actionsWarn) {
            $tips[] = 'Moderation intensity was high; balance with chat support.';
        }
        if ($messages >= $positiveMessages && $activeHours >= $positiveHours) {
            $tips[] = 'Great presence yesterday — keep it up.';
        }
    }

    if (empty($tips)) {
        $tips[] = 'Nice work yesterday. Keep the momentum.';
    }

    $lines = [];
    $lines[] = '<b>Daily Micro‑Feedback</b>';
    $lines[] = $chatTitle . ' · ' . $dateLabel;
    $lines[] = 'Yesterday: ' . $messages . ' msgs · ' . number_format($activeHours, 1) . 'h active · ' . $actions . ' actions';
    $lines[] = '';
    foreach (array_slice($tips, 0, 3) as $tip) {
        $lines[] = '• ' . htmlspecialchars($tip, ENT_QUOTES, 'UTF-8');
    }

    return implode("\n", $lines);
}

$ownerIds = $config['owner_user_ids'] ?? [];
$ownerIds = array_values(array_filter(array_map('intval', is_array($ownerIds) ? $ownerIds : [])));
$midMonthEnabled = (bool)($config['premium']['notifications']['mid_month_alert'] ?? true);
$congratsEnabled = (bool)($config['premium']['notifications']['congrats'] ?? true);

try {
    $rows = $db->fetchAll('SELECT * FROM settings WHERE auto_report_enabled = 1 OR progress_report_enabled = 1 OR weekly_summary_enabled = 1 OR inactivity_alert_enabled = 1 OR ai_review_enabled = 1 OR retention_alert_enabled = 1 OR inactivity_spike_enabled = 1 OR daily_feedback_enabled = 1');
} catch (Throwable $e) {
    echo "Report columns missing. Run migrations/002_auto_reports.sql, migrations/006_progress_reports.sql, migrations/015_weekly_summary.sql, migrations/016_inactivity_alerts.sql, migrations/017_ai_review.sql, migrations/018_retention_alerts.sql, migrations/019_inactivity_spikes.sql, and migrations/020_goals_and_feedback.sql\n";
    exit(1);
}

foreach ($rows as $row) {
    $chatId = $row['chat_id'];
    $isPremium = $subscriptions->isPremium($chatId);
    $timezone = $row['timezone'] ?? ($config['timezone'] ?? 'UTC');

    $nowLocal = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $currentDay = (int)$nowLocal->format('j');
    $currentHour = (int)$nowLocal->format('G');

    if (!empty($row['auto_report_enabled'])) {
        $day = (int)($row['auto_report_day'] ?? 1);
        $hour = (int)($row['auto_report_hour'] ?? 9);

        if ($currentDay >= $day && $currentHour >= $hour) {
            $targetMonth = $nowLocal->modify('first day of last month')->format('Y-m');
            if (empty($row['auto_report_last_month']) || $row['auto_report_last_month'] !== $targetMonth) {
                $stats = $statsService->getMonthlyStats($chatId, $targetMonth);
                if (empty($stats['mods'])) {
                    Logger::info('Auto report skipped for chat ' . $chatId . ' (no mods)');
                } else {
                    $budget = (float)($stats['settings']['reward_budget'] ?? 0);
                    $filePath = $rewardSheet->generate($chatId, $targetMonth, $budget);
                    $caption = 'Auto report for ' . $stats['range']['label'] . ' (budget: ' . number_format($budget, 2) . ')';

                    $chatRow = $db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
                    $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);
                    $meta = buildReportMeta($config, (int)$chatId, $chatTitle, 'Auto Report', $stats['range']['label'] ?? null, $budget, $timezone);
                    $sent = $reporter->sendDocument($filePath, $caption, ['report_meta' => $meta]);
                    if ($sent) {
                        $settingsService->updateAutoReportLast($chatId, $targetMonth);
                        Logger::info('Auto report sent for chat ' . $chatId . ' month ' . $targetMonth);
                        if ($isPremium && $congratsEnabled && !empty($ownerIds) && !$notifications->wasSent($chatId, 'congrats', $targetMonth)) {
                            $context = $rewardContext->build($chatId, $targetMonth);
                            $context['chat_id'] = (int)$chatId;
                            $context['month'] = $targetMonth;
                            $context['source'] = 'auto_congrats';
                            $ranked = $rewardService->rankAndReward($stats['mods'], $budget, $context);
                            $top = array_slice($ranked, 0, 3);
                            $lines = ['Congrats templates for ' . $stats['range']['label'] . ':'];
                            foreach ($top as $mod) {
                                $lines[] = 'Congrats ' . $mod['display_name'] . ' for top performance! Reward: ' . number_format($mod['reward'], 2);
                            }
                            $reporter->sendMessage(implode("\n", $lines), ['parse_mode' => 'HTML', 'report_meta' => $meta]);
                            $notifications->markSent($chatId, 'congrats', $targetMonth);
                        }
                    } else {
                        Logger::error('Auto report failed for chat ' . $chatId);
                    }
                }
            }
        }
    }

    if (!empty($row['ai_review_enabled'])) {
        $day = (int)($row['ai_review_day'] ?? 1);
        $hour = (int)($row['ai_review_hour'] ?? 9);

        if ($currentDay >= $day && $currentHour >= $hour) {
            $targetMonth = $nowLocal->modify('first day of last month')->format('Y-m');
            if (empty($row['ai_review_last_month']) || $row['ai_review_last_month'] !== $targetMonth) {
                if (!$isPremium) {
                    Logger::info('AI review skipped for chat ' . $chatId . ' (not premium)');
                } else {
                    $report = $performanceReview->buildReport($chatId, $targetMonth);
                    if (empty($report['reviews'])) {
                        Logger::info('AI review skipped for chat ' . $chatId . ' (no mods)');
                    } else {
                        $chatRow = $db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
                        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);
                        $lines = $performanceReview->buildLines($report, $chatTitle);
                        $message = implode("\n", $lines);

                        if ($reporter->sendMessage($message, ['parse_mode' => 'HTML'])) {
                            $settingsService->updateAiReviewLast($chatId, $targetMonth);
                            Logger::info('AI review sent for chat ' . $chatId . ' month ' . $targetMonth);
                        }
                    }
                }
            }
        }
    }

    if (!empty($row['retention_alert_enabled'])) {
        $day = (int)($row['retention_alert_day'] ?? 2);
        $hour = (int)($row['retention_alert_hour'] ?? 10);

        if ($currentDay >= $day && $currentHour >= $hour) {
            $targetMonth = $nowLocal->modify('first day of last month')->format('Y-m');
            if (empty($row['retention_alert_last_month']) || $row['retention_alert_last_month'] !== $targetMonth) {
                if (!$isPremium) {
                    Logger::info('Retention alerts skipped for chat ' . $chatId . ' (not premium)');
                } else {
                    $report = $retentionRisk->buildReport($chatId, $targetMonth, null);
                    if (($report['status'] ?? '') === 'no_mods') {
                        Logger::info('Retention alerts skipped for chat ' . $chatId . ' (no mods)');
                    } else {
                        $chatRow = $db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
                        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);
                        $lines = $retentionRisk->buildLines($report, $chatTitle);
                        $message = implode("\n", $lines);

                        if ($reporter->sendMessage($message, ['parse_mode' => 'HTML'])) {
                            $settingsService->updateRetentionAlertLast($chatId, $targetMonth);
                            Logger::info('Retention alerts sent for chat ' . $chatId . ' month ' . $targetMonth);
                        }
                    }
                }
            }
        }
    }

    if (!empty($row['inactivity_spike_enabled'])) {
        $hour = (int)($row['inactivity_spike_hour'] ?? 10);
        $threshold = (float)($row['inactivity_spike_threshold'] ?? ($config['inactivity_spike_defaults']['threshold'] ?? 35));

        if ($currentHour >= $hour) {
            $period = $nowLocal->format('Y-m-d');
            if (!$notifications->wasSent($chatId, 'inactivity_spike', $period)) {
                if (!$isPremium) {
                    Logger::info('Inactivity spike alerts skipped for chat ' . $chatId . ' (not premium)');
                } else {
                    $spikeConfig = $config['inactivity_spike'] ?? [];
                    $windowDays = (int)($spikeConfig['window_days'] ?? 7);
                    $minPrevMessages = (int)($spikeConfig['min_prev_messages'] ?? 200);
                    $minPrevActiveHours = (float)($spikeConfig['min_prev_active_hours'] ?? 20);
                    $minPrevActiveMods = (int)($spikeConfig['min_prev_active_mods'] ?? 3);

                    $currentStats = $statsService->getRollingStats($chatId, $windowDays, $nowLocal);
                    $prevStats = $statsService->getRollingStats($chatId, $windowDays, $nowLocal->modify('-' . $windowDays . ' days'));

                    if (!empty($currentStats['mods']) && !empty($prevStats['mods'])) {
                        $currSummary = $currentStats['summary'] ?? [];
                        $prevSummary = $prevStats['summary'] ?? [];

                        $currMessages = (float)($currSummary['messages'] ?? 0);
                        $prevMessages = (float)($prevSummary['messages'] ?? 0);
                        $currActiveHours = ((float)($currSummary['active_minutes'] ?? 0)) / 60;
                        $prevActiveHours = ((float)($prevSummary['active_minutes'] ?? 0)) / 60;

                        $currActiveMods = 0;
                        foreach ($currentStats['mods'] as $mod) {
                            if ((int)($mod['messages'] ?? 0) > 0 || (float)($mod['active_minutes'] ?? 0) > 0) {
                                $currActiveMods++;
                            }
                        }
                        $prevActiveMods = 0;
                        foreach ($prevStats['mods'] as $mod) {
                            if ((int)($mod['messages'] ?? 0) > 0 || (float)($mod['active_minutes'] ?? 0) > 0) {
                                $prevActiveMods++;
                            }
                        }

                        $reasons = [];
                        $dropMessages = percentDrop($currMessages, $prevMessages);
                        if ($prevMessages >= $minPrevMessages && $dropMessages !== null && $dropMessages >= $threshold) {
                            $reasons[] = 'Messages down ' . number_format($dropMessages, 1) . '% (' . number_format($prevMessages) . '→' . number_format($currMessages) . ')';
                        }

                        $dropActive = percentDrop($currActiveHours, $prevActiveHours);
                        if ($prevActiveHours >= $minPrevActiveHours && $dropActive !== null && $dropActive >= $threshold) {
                            $reasons[] = 'Active hours down ' . number_format($dropActive, 1) . '% (' . number_format($prevActiveHours, 1) . 'h→' . number_format($currActiveHours, 1) . 'h)';
                        }

                        $dropActiveMods = percentDrop($currActiveMods, $prevActiveMods);
                        if ($prevActiveMods >= $minPrevActiveMods && $dropActiveMods !== null && $dropActiveMods >= $threshold) {
                            $reasons[] = 'Active mods down ' . number_format($dropActiveMods, 1) . '% (' . number_format($prevActiveMods) . '→' . number_format($currActiveMods) . ')';
                        }

                        if (!empty($reasons)) {
                            $chatRow = $db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
                            $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);
                            $lines = [];
                            $lines[] = '<b>Inactivity Spike Alert</b>';
                            $lines[] = $chatTitle . ' · Last ' . $windowDays . ' days vs previous ' . $windowDays . ' days';
                            $lines[] = 'Threshold: ' . number_format($threshold, 0) . '% drop';
                            $lines[] = '';
                            foreach ($reasons as $reason) {
                                $lines[] = '• ' . $reason;
                            }

                            $message = implode("\n", $lines);
                            if ($reporter->sendMessage($message, ['parse_mode' => 'HTML'])) {
                                $notifications->markSent($chatId, 'inactivity_spike', $period);
                                Logger::info('Inactivity spike alert sent for chat ' . $chatId . ' date ' . $period);
                            }
                        }
                    }
                }
            }
        }
    }

    if (!empty($row['progress_report_enabled'])) {
        $day = (int)($row['progress_report_day'] ?? 15);
        $hour = (int)($row['progress_report_hour'] ?? 12);

        if ($currentDay >= $day && $currentHour >= $hour) {
            $targetMonth = $nowLocal->format('Y-m');
            if (empty($row['progress_report_last_month']) || $row['progress_report_last_month'] !== $targetMonth) {
                $stats = $statsService->getMonthToDateStats($chatId, $nowLocal);
                if (empty($stats['mods'])) {
                    Logger::info('Progress report skipped for chat ' . $chatId . ' (no mods)');
                } else {
                    $budget = (float)($stats['settings']['reward_budget'] ?? 0);
                    $suffix = $stats['range']['month'] . '-mtd';
                    $filePath = $rewardSheet->generate($chatId, null, $budget, $stats, $suffix);
                    $caption = 'Progress report (MTD) for ' . $stats['range']['label'] . ' (budget: ' . number_format($budget, 2) . ')';

                    $chatRow = $db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
                    $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);
                    $meta = buildReportMeta($config, (int)$chatId, $chatTitle, 'Progress Report (MTD)', $stats['range']['label'] ?? null, $budget, $timezone);
                    $sent = $reporter->sendDocument($filePath, $caption, ['report_meta' => $meta]);
                    if ($sent) {
                        $settingsService->updateProgressReportLast($chatId, $targetMonth);
                        Logger::info('Progress report sent for chat ' . $chatId . ' month ' . $targetMonth);
                        if ($isPremium && $midMonthEnabled && !empty($ownerIds) && !$notifications->wasSent($chatId, 'mid_month_alert', $targetMonth)) {
                            $eligibility = $config['eligibility'] ?? [];
                            $minDays = (int)($eligibility['min_days_active'] ?? 0);
                            $minMessages = (int)($eligibility['min_messages'] ?? 0);
                            $atRisk = [];
                            foreach ($stats['mods'] as $mod) {
                                if (($minDays > 0 && ($mod['days_active'] ?? 0) < $minDays) ||
                                    ($minMessages > 0 && ($mod['messages'] ?? 0) < $minMessages)) {
                                    $atRisk[] = $mod['display_name'];
                                }
                            }
                            if (!empty($atRisk)) {
                                $lines = ['Mid-month at-risk mods:', implode(', ', $atRisk)];
                                $reporter->sendMessage(implode("\n", $lines), ['parse_mode' => 'HTML', 'report_meta' => $meta]);
                                $notifications->markSent($chatId, 'mid_month_alert', $targetMonth);
                            }
                        }
                    } else {
                        Logger::error('Progress report failed for chat ' . $chatId);
                    }
                }
            }
        }
    }

    if (!empty($row['weekly_summary_enabled'])) {
        $weekday = (int)($row['weekly_summary_weekday'] ?? 1);
        $hour = (int)($row['weekly_summary_hour'] ?? 10);

        $currentWeekday = (int)$nowLocal->format('N'); // 1=Mon ... 7=Sun
        if ($currentWeekday === $weekday && $currentHour >= $hour) {
            $weekKey = $nowLocal->format('o-\WW');
            if (empty($row['weekly_summary_last_week']) || $row['weekly_summary_last_week'] !== $weekKey) {
                $stats = $statsService->getRollingStats($chatId, 7, $nowLocal);
                if (empty($stats['mods'])) {
                    Logger::info('Weekly summary skipped for chat ' . $chatId . ' (no mods)');
                } else {
                    $chatRow = $db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
                    $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);

                    $top = array_slice($stats['mods'], 0, 5);
                    $summary = $stats['summary'] ?? [];
                    $lines = [];
                    $lines[] = '<b>Weekly Summary</b>';
                    $lines[] = $chatTitle . ' · ' . $stats['range']['label'];
                    $lines[] = 'Messages: ' . number_format((int)($summary['messages'] ?? 0));
                    $lines[] = 'Active hours: ' . number_format(((float)($summary['active_minutes'] ?? 0)) / 60, 1) . 'h';
                    $lines[] = 'Actions: ' . number_format((int)($summary['warnings'] ?? 0) + (int)($summary['mutes'] ?? 0) + (int)($summary['bans'] ?? 0));
                    $lines[] = '';
                    $lines[] = '<b>Top 5 Mods</b>';
                    $rank = 1;
                    foreach ($top as $mod) {
                        $activeH = ((float)($mod['active_minutes'] ?? 0)) / 60;
                        $presenceH = ((float)($mod['membership_minutes'] ?? 0)) / 60;
                        $actions = (int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0);
                        $lines[] = $rank . '. ' . $mod['display_name'] . ' · ' . number_format($activeH, 1) . 'h active · ' . number_format($presenceH, 1) . 'h presence · ' . $actions . ' actions';
                        $rank++;
                    }

                    $weights = $config['score_weights'] ?? [];
                    $ranked = [];
                    foreach ($stats['mods'] as $mod) {
                        $messages = (int)($mod['messages'] ?? 0);
                        $activeMinutes = (float)($mod['active_minutes'] ?? 0);
                        $daysActive = (int)($mod['days_active'] ?? 0);
                        $warnings = (int)($mod['warnings'] ?? 0);
                        $mutes = (int)($mod['mutes'] ?? 0);
                        $bans = (int)($mod['bans'] ?? 0);
                        $actions = $warnings + $mutes + $bans;
                        $roleMultiplier = (float)($mod['role_multiplier'] ?? 1.0);

                        $chatScore = 0.0;
                        $chatScore += log(1 + $messages) * ($weights['message'] ?? 1.0);
                        $chatScore += sqrt($activeMinutes) * ($weights['active_minute'] ?? 0.0);
                        $chatScore += $daysActive * ($weights['day_active'] ?? 0.0);
                        $chatScore *= $roleMultiplier;

                        $actionScore = 0.0;
                        $actionScore += $warnings * ($weights['warn'] ?? 1.0);
                        $actionScore += $mutes * ($weights['mute'] ?? 1.0);
                        $actionScore += $bans * ($weights['ban'] ?? 1.0);
                        $actionScore *= $roleMultiplier;

                        $ranked[] = [
                            'display_name' => $mod['display_name'],
                            'messages' => $messages,
                            'active_hours' => $activeMinutes / 60,
                            'actions' => $actions,
                            'chat_score' => $chatScore,
                            'action_score' => $actionScore,
                        ];
                    }

                    usort($ranked, fn($a, $b) => $b['chat_score'] <=> $a['chat_score']);
                    $chatRank = array_slice($ranked, 0, 5);
                    $rankedActions = $ranked;
                    usort($rankedActions, fn($a, $b) => $b['action_score'] <=> $a['action_score']);
                    $actionRank = array_slice($rankedActions, 0, 5);

                    $lines[] = '';
                    $lines[] = '<b>Chat Work Ranking</b>';
                    $rank = 1;
                    foreach ($chatRank as $mod) {
                        $lines[] = $rank . '. ' . $mod['display_name'] . ' · ' . number_format($mod['chat_score'], 2) . ' score · ' . number_format($mod['active_hours'], 1) . 'h active · ' . number_format((int)$mod['messages']) . ' msgs';
                        $rank++;
                    }
                    $lines[] = '';
                    $lines[] = '<b>Moderation Actions Ranking</b>';
                    $rank = 1;
                    foreach ($actionRank as $mod) {
                        $lines[] = $rank . '. ' . $mod['display_name'] . ' · ' . number_format($mod['action_score'], 2) . ' score · ' . $mod['actions'] . ' actions';
                        $rank++;
                    }

                    $eligibility = $config['eligibility'] ?? [];
                    $minDays = (int)($eligibility['min_days_active'] ?? 0);
                    $minMessages = (int)($eligibility['min_messages'] ?? 0);
                    $alerts = [];
                    foreach ($stats['mods'] as $mod) {
                        if ($minDays > 0 && ($mod['days_active'] ?? 0) < $minDays) {
                            $alerts[] = $mod['display_name'] . ' (low days)';
                            continue;
                        }
                        if ($minMessages > 0 && ($mod['messages'] ?? 0) < $minMessages) {
                            $alerts[] = $mod['display_name'] . ' (low msgs)';
                        }
                    }
                    if (!empty($alerts)) {
                        $lines[] = '';
                        $lines[] = '<b>Alerts</b>';
                        $lines[] = implode(', ', array_slice($alerts, 0, 8));
                    }

                    $badges = buildPerformanceBadges($stats['mods'] ?? [], $config);
                    if (!empty($badges)) {
                        $lines[] = '';
                        $lines[] = '<b>Performance Badges</b>';
                        foreach ($badges as $badge) {
                            $line = $badge['title'] . ': ' . $badge['name'];
                            if (!empty($badge['meta'])) {
                                $line .= ' (' . $badge['meta'] . ')';
                            }
                            $lines[] = $line;
                        }
                    }

                    $monthKey = $nowLocal->format('Y-m');
                    $monthStats = $statsService->getMonthlyStats($chatId, $monthKey);
                    $prevMonthKey = $nowLocal->modify('first day of last month')->format('Y-m');
                    $prevMonthStats = $statsService->getMonthlyStats($chatId, $prevMonthKey);
                    $burnoutRisks = buildBurnoutRisks($monthStats['mods'] ?? [], $prevMonthStats['mods'] ?? [], $config);
                    if (!empty($burnoutRisks)) {
                        $lines[] = '';
                        $lines[] = '<b>Burnout Risk (MTD)</b>';
                        foreach ($burnoutRisks as $risk) {
                            $line = $risk['name'] . ' · ' . number_format((float)($risk['active_hours'] ?? 0), 1) . 'h';
                            if (($risk['consistency_drop'] ?? null) !== null) {
                                $line .= ' · Consistency -' . number_format((float)$risk['consistency_drop'], 1) . '%';
                            }
                            if (($risk['improvement'] ?? null) !== null && $risk['improvement'] < 0) {
                                $line .= ' · Score ' . number_format((float)$risk['improvement'], 1) . '%';
                            }
                            $lines[] = $line;
                        }
                    }

                    $tldr = buildWeeklyTldr($stats, $chatTitle, 7);
                    $message = implode("\n", $lines);
                    $meta = buildReportMeta($config, (int)$chatId, $chatTitle, 'Weekly Summary', $stats['range']['label'] ?? null, null, $timezone);
                    $sent = $reporter->sendMessage($tldr, ['parse_mode' => 'HTML', 'report_meta' => $meta]);
                    $sent = $reporter->sendMessage($message, ['parse_mode' => 'HTML']) || $sent;
                    if ($sent) {
                        $settingsService->updateWeeklySummaryLast($chatId, $weekKey);
                        Logger::info('Weekly summary sent for chat ' . $chatId . ' week ' . $weekKey);
                    }
                }
            }
        }
    }

    if (!empty($row['inactivity_alert_enabled'])) {
        $days = (int)($row['inactivity_alert_days'] ?? 7);
        $hour = (int)($row['inactivity_alert_hour'] ?? 10);

        if ($currentHour >= $hour) {
            $period = $nowLocal->format('Y-m-d');
            if (!$notifications->wasSent($chatId, 'inactivity_alert', $period)) {
                $startLocal = $nowLocal->modify('-' . max(1, $days) . ' days')->setTime(0, 0, 0);
                $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

                $mods = $db->fetchAll(
                    'SELECT u.id as user_id, u.username, u.first_name, u.last_name
                     FROM chat_members cm
                     JOIN users u ON u.id = cm.user_id
                     WHERE cm.chat_id = ? AND cm.is_mod = 1',
                    [$chatId]
                );

                if (!empty($mods)) {
                    $activeRows = $db->fetchAll(
                        'SELECT DISTINCT user_id FROM messages WHERE chat_id = ? AND sent_at >= ?',
                        [$chatId, $startUtc]
                    );
                    $activeMap = [];
                    foreach ($activeRows as $activeRow) {
                        $activeMap[(int)$activeRow['user_id']] = true;
                    }

                    $inactive = [];
                    foreach ($mods as $mod) {
                        if (!isset($activeMap[(int)$mod['user_id']])) {
                            $inactive[] = $mod;
                        }
                    }

                    if (!empty($inactive)) {
                        $chatRow = $db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
                        $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);

                        $lines = [];
                        $lines[] = '<b>Inactivity Alert</b>';
                        $lines[] = $chatTitle . ' · No activity in last ' . $days . ' days';
                        $lines[] = '';
                        foreach (array_slice($inactive, 0, 15) as $mod) {
                            $name = $mod['username'] ? '@' . $mod['username'] : trim(($mod['first_name'] ?? '') . ' ' . ($mod['last_name'] ?? ''));
                            if ($name === '') {
                                $name = 'User ' . $mod['user_id'];
                            }
                            $lines[] = '• ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                        }

                        $message = implode("\n", $lines);
                        if ($reporter->sendMessage($message, ['parse_mode' => 'HTML'])) {
                            $notifications->markSent($chatId, 'inactivity_alert', $period);
                            Logger::info('Inactivity alert sent for chat ' . $chatId . ' date ' . $period);
                        }
                    }
                }
            }
        }
    }

    if (!empty($row['daily_feedback_enabled'])) {
        $allowModDms = (bool)($config['reports']['send_to_mods'] ?? false);
        if (!$allowModDms) {
            Logger::info('Daily micro-feedback skipped for chat ' . $chatId . ' (mod DMs disabled)');
        } else {
        $hour = (int)($row['daily_feedback_hour'] ?? 20);
        if ($currentHour >= $hour) {
            $todayKey = $nowLocal->format('Y-m-d');
            if (empty($row['daily_feedback_last_date']) || $row['daily_feedback_last_date'] !== $todayKey) {
                $yesterdayEnd = $nowLocal->modify('-1 day')->setTime(23, 59, 59);
                $stats = $statsService->getRollingStats($chatId, 1, $yesterdayEnd);
                if (!empty($stats['mods'])) {
                    $chatRow = $db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
                    $chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);
                    $dateLabel = $yesterdayEnd->format('Y-m-d');
                    foreach ($stats['mods'] as $mod) {
                        $userId = (int)$mod['user_id'];
                        $message = buildMicroFeedbackMessage($mod, $chatTitle, $dateLabel, $config);
                        $tg->sendMessage($userId, $message, ['parse_mode' => 'HTML']);
                    }
                    $settingsService->updateDailyFeedbackLast($chatId, $todayKey);
                    Logger::info('Daily micro-feedback sent for chat ' . $chatId . ' date ' . $todayKey);
                }
            }
        }
        }
    }
}
