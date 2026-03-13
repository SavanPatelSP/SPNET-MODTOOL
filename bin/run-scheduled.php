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
    $rows = $db->fetchAll('SELECT * FROM settings WHERE auto_report_enabled = 1');
} catch (Throwable $e) {
    echo "Auto report columns missing. Run migrations/002_auto_reports.sql\n";
    exit(1);
}

foreach ($rows as $row) {
    $chatId = $row['chat_id'];
    $timezone = $row['timezone'] ?? ($config['timezone'] ?? 'UTC');
    $day = (int)($row['auto_report_day'] ?? 1);
    $hour = (int)($row['auto_report_hour'] ?? 9);

    $nowLocal = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $currentDay = (int)$nowLocal->format('j');
    $currentHour = (int)$nowLocal->format('G');

    if ($currentDay < $day || $currentHour < $hour) {
        continue;
    }

    $targetMonth = $nowLocal->modify('first day of last month')->format('Y-m');
    if (!empty($row['auto_report_last_month']) && $row['auto_report_last_month'] === $targetMonth) {
        continue;
    }

    $stats = $statsService->getMonthlyStats($chatId, $targetMonth);
    if (empty($stats['mods'])) {
        Logger::info('Auto report skipped for chat ' . $chatId . ' (no mods)');
        continue;
    }

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
