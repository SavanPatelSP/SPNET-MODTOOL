<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Services\ExternalImportService;
use App\Telegram;
use App\Logger;

$opts = getopt('', ['file:', 'chat::', 'month::', 'replace']);
$file = $opts['file'] ?? null;

if (!$file) {
    echo "Usage: php bin/import-chatkeeper.php --file=/path/analysis_users.csv --chat=-1001234567890 --month=YYYY-MM [--replace]\n";
    exit(1);
}

if (!file_exists($file)) {
    echo "File not found: {$file}\n";
    exit(1);
}

$db = new Database($config['db']);
$token = $config['bot_token'] ?? null;
if ($token && $token !== 'YOUR_TELEGRAM_BOT_TOKEN') {
    $tg = new Telegram($token, $config['telegram'] ?? []);
    Logger::initChannel($tg, $config);
}

$chatId = $opts['chat'] ?? null;
if ($chatId === null || $chatId === '') {
    $chats = $db->fetchAll("SELECT id, title, type FROM chats WHERE type IN ('group','supergroup')");
    if (count($chats) === 1) {
        $chatId = (int)$chats[0]['id'];
    } else {
        echo "Please provide --chat. Known chats:\n";
        foreach ($chats as $chat) {
            $title = $chat['title'] ?: 'Untitled';
            echo $chat['id'] . " | " . $title . "\n";
        }
        exit(1);
    }
}
$chatId = (int)$chatId;

$month = $opts['month'] ?? null;
$replace = isset($opts['replace']);

$importer = new ExternalImportService($db, $config);
$result = $importer->importChatkeeper($file, $chatId, $month, $replace);

if (!($result['ok'] ?? false)) {
    echo ($result['error'] ?? 'Import failed.') . "\n";
    Logger::error('ChatKeeper import failed: ' . ($result['error'] ?? 'unknown'));
    exit(1);
}

echo "Imported {$result['imported']} rows for chat {$chatId} month {$result['month']} (source: {$result['source']}).\n";
if (!empty(($config['logging']['log_imports'] ?? false))) {
    Logger::info('ChatKeeper import for chat ' . $chatId . ' month ' . $result['month'] . ' rows ' . $result['imported']);
}
