<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Telegram;
use App\Services\SettingsService;
use App\Services\StatsService;
use App\Services\RewardService;
use App\Reports\RewardSheet;
use App\Logger;

$token = $config['bot_token'] ?? null;
if (!$token || $token === 'YOUR_TELEGRAM_BOT_TOKEN') {
    echo "Missing bot token in config.php\n";
    exit(1);
}

$db = new Database($config['db']);
$tg = new Telegram($token);
$settingsService = new SettingsService($db, $config);
$statsService = new StatsService($db, $settingsService, $config);
$rewardService = new RewardService($config);
$rewardSheet = new RewardSheet($statsService, $rewardService, $config);

try {
    $rows = $db->fetchAll('SELECT * FROM settings WHERE auto_report_enabled = 1 OR progress_report_enabled = 1');
} catch (Throwable $e) {
    echo "Report columns missing. Run migrations/002_auto_reports.sql and migrations/006_progress_reports.sql\n";
    exit(1);
}

foreach ($rows as $row) {
    $chatId = $row['chat_id'];
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
                    } else {
                        Logger::error('Auto report failed for chat ' . $chatId);
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
                    } else {
                        Logger::error('Progress report failed for chat ' . $chatId);
                    }
                }
            }
        }
    }
}
