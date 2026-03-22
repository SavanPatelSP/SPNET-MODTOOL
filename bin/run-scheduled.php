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
$changelog = new ChangelogService();
$changelog->sendIfUpdated($tg, $config, 'scheduler');
$settingsService = new SettingsService($db, $config);
$statsService = new StatsService($db, $settingsService, $config);
$rewardService = new RewardService($config);
$rewardContext = new RewardContextService($db, $config);
$rewardHistory = new RewardHistoryService($db);
$archive = new ArchiveService($db);
$subscriptions = new SubscriptionService($db, $config);
$notifications = new NotificationService($db);
$performanceReview = new PerformanceReviewService($statsService, $settingsService, $config);
$rewardSheet = new RewardSheet($statsService, $rewardService, $config, $rewardContext, $rewardHistory, $archive);

function sendChunkedMessage(Telegram $tg, int|string $chatId, string $text, int $limit = 3500): void
{
    $lines = explode("\n", $text);
    $chunks = [];
    $current = '';
    foreach ($lines as $line) {
        $candidate = $current === '' ? $line : $current . "\n" . $line;
        if (strlen($candidate) > $limit) {
            if ($current !== '') {
                $chunks[] = $current;
                $current = $line;
                continue;
            }
            $longParts = str_split($line, $limit);
            foreach ($longParts as $part) {
                $chunks[] = $part;
            }
            $current = '';
            continue;
        }
        $current = $candidate;
    }
    if ($current !== '') {
        $chunks[] = $current;
    }

    foreach ($chunks as $chunk) {
        $tg->sendMessage($chatId, $chunk, ['parse_mode' => 'HTML']);
    }
}

$ownerIds = $config['owner_user_ids'] ?? [];
$ownerIds = array_values(array_filter(array_map('intval', is_array($ownerIds) ? $ownerIds : [])));
$managerIds = $config['manager_user_ids'] ?? [];
$managerIds = array_values(array_filter(array_map('intval', is_array($managerIds) ? $managerIds : [])));
$ownerDmEnabled = (bool)($config['premium']['notifications']['owner_dm'] ?? true);
$midMonthEnabled = (bool)($config['premium']['notifications']['mid_month_alert'] ?? true);
$congratsEnabled = (bool)($config['premium']['notifications']['congrats'] ?? true);

try {
    $rows = $db->fetchAll('SELECT * FROM settings WHERE auto_report_enabled = 1 OR progress_report_enabled = 1 OR weekly_summary_enabled = 1 OR inactivity_alert_enabled = 1 OR ai_review_enabled = 1');
} catch (Throwable $e) {
    echo "Report columns missing. Run migrations/002_auto_reports.sql, migrations/006_progress_reports.sql, migrations/015_weekly_summary.sql, migrations/016_inactivity_alerts.sql, and migrations/017_ai_review.sql\n";
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

                    $resp = $tg->sendDocument($chatId, $filePath, $caption);
                    if ($resp['ok'] ?? false) {
                        $settingsService->updateAutoReportLast($chatId, $targetMonth);
                        Logger::info('Auto report sent for chat ' . $chatId . ' month ' . $targetMonth);
                        if ($isPremium && $ownerDmEnabled && !empty($ownerIds) && !$notifications->wasSent($chatId, 'auto_report_owner', $targetMonth)) {
                            foreach ($ownerIds as $ownerId) {
                                $tg->sendDocument($ownerId, $filePath, $caption);
                            }
                            $notifications->markSent($chatId, 'auto_report_owner', $targetMonth);
                        }
                        if ($isPremium && $congratsEnabled && !empty($ownerIds) && !$notifications->wasSent($chatId, 'congrats', $targetMonth)) {
                            $ranked = $rewardService->rankAndReward($stats['mods'], $budget, $rewardContext->build($chatId, $targetMonth));
                            $top = array_slice($ranked, 0, 3);
                            $lines = ['Congrats templates for ' . $stats['range']['label'] . ':'];
                            foreach ($top as $mod) {
                                $lines[] = 'Congrats ' . $mod['display_name'] . ' for top performance! Reward: ' . number_format($mod['reward'], 2);
                            }
                            foreach ($ownerIds as $ownerId) {
                                $tg->sendMessage($ownerId, implode("\n", $lines), ['parse_mode' => 'HTML']);
                            }
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

                        $targets = !empty($managerIds) ? $managerIds : $ownerIds;
                        if (empty($targets)) {
                            Logger::info('AI review skipped for chat ' . $chatId . ' (no managers)');
                        } else {
                            foreach ($targets as $managerId) {
                                sendChunkedMessage($tg, $managerId, $message);
                            }
                            $settingsService->updateAiReviewLast($chatId, $targetMonth);
                            Logger::info('AI review sent for chat ' . $chatId . ' month ' . $targetMonth);
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

                    $resp = $tg->sendDocument($chatId, $filePath, $caption);
                    if ($resp['ok'] ?? false) {
                        $settingsService->updateProgressReportLast($chatId, $targetMonth);
                        Logger::info('Progress report sent for chat ' . $chatId . ' month ' . $targetMonth);
                        if ($isPremium && $ownerDmEnabled && !empty($ownerIds) && !$notifications->wasSent($chatId, 'progress_owner', $targetMonth)) {
                            foreach ($ownerIds as $ownerId) {
                                $tg->sendDocument($ownerId, $filePath, $caption);
                            }
                            $notifications->markSent($chatId, 'progress_owner', $targetMonth);
                        }
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
                                foreach ($ownerIds as $ownerId) {
                                    $tg->sendMessage($ownerId, implode("\n", $lines), ['parse_mode' => 'HTML']);
                                }
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

                    $targets = !empty($managerIds) ? $managerIds : $ownerIds;
                    if (empty($targets)) {
                        Logger::info('Weekly summary skipped for chat ' . $chatId . ' (no managers)');
                    } else {
                        $message = implode("\n", $lines);
                        foreach ($targets as $managerId) {
                            $tg->sendMessage($managerId, $message, ['parse_mode' => 'HTML']);
                        }
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

                        $targets = !empty($managerIds) ? $managerIds : $ownerIds;
                        if (!empty($targets)) {
                            $message = implode("\n", $lines);
                            foreach ($targets as $managerId) {
                                $tg->sendMessage($managerId, $message, ['parse_mode' => 'HTML']);
                            }
                            $notifications->markSent($chatId, 'inactivity_alert', $period);
                            Logger::info('Inactivity alert sent for chat ' . $chatId . ' date ' . $period);
                        }
                    }
                }
            }
        }
    }
}
