<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Services\SettingsService;
use App\Services\StatsService;
use App\Services\RewardService;
use App\Reports\RewardSheet;
use App\Reports\RewardCsv;
use App\Reports\MultiChatReport;

$dashboardConfig = $config['dashboard'] ?? [];
$token = $dashboardConfig['token'] ?? null;
$provided = $_GET['token'] ?? ($_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? null);

if ($token && $provided !== $token) {
    http_response_code(403);
    echo 'Forbidden: invalid dashboard token.';
    exit;
}

if (!$token) {
    http_response_code(403);
    echo 'Dashboard token not set. Configure dashboard.token in config.local.php.';
    exit;
}

$type = $_GET['type'] ?? 'html';
$chatId = $_GET['chat_id'] ?? null;
$month = $_GET['month'] ?? null;
$budget = isset($_GET['budget']) && $_GET['budget'] !== '' ? (float)$_GET['budget'] : null;

$db = new Database($config['db']);
$settingsService = new SettingsService($db, $config);
$statsService = new StatsService($db, $settingsService, $config);
$rewardService = new RewardService($config);

if ($type === 'summary') {
    $chats = $db->fetchAll("SELECT id, type FROM chats WHERE type IN ('group','supergroup')");
    $chatIds = array_map(static fn($row) => $row['id'], $chats);
    if (empty($chatIds)) {
        echo 'No chats found.';
        exit;
    }
    $report = new MultiChatReport($statsService, $rewardService, $config);
    $file = $report->generate($chatIds, $month, $budget);
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    readfile($file);
    exit;
}

if (!$chatId || $chatId === 'all') {
    echo 'Missing chat_id.';
    exit;
}

$bundle = $statsService->getMonthlyStats($chatId, $month);
$effectiveBudget = $budget ?? (float)($bundle['settings']['reward_budget'] ?? 0);

if ($type === 'csv') {
    $report = new RewardCsv($statsService, $rewardService);
    $file = $report->generate($chatId, $month, $effectiveBudget);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    readfile($file);
    exit;
}

$report = new RewardSheet($statsService, $rewardService, $config);
$file = $report->generate($chatId, $month, $effectiveBudget);
header('Content-Type: text/html');
header('Content-Disposition: attachment; filename="' . basename($file) . '"');
readfile($file);
exit;
