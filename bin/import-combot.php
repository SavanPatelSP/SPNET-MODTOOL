<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Services\ExternalImportService;

$opts = getopt('', ['file:', 'chat::', 'month::', 'replace']);
$file = $opts['file'] ?? null;

if (!$file) {
    echo "Usage: php bin/import-combot.php --file=/path/combot.csv --chat=-1001234567890 --month=YYYY-MM [--replace]\n";
    exit(1);
}

if (!file_exists($file)) {
    echo "File not found: {$file}\n";
    exit(1);
}

$db = new Database($config['db']);

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
$result = $importer->importCombot($file, $chatId, $month, $replace);

if (!($result['ok'] ?? false)) {
    echo ($result['error'] ?? 'Import failed.') . "\n";
    exit(1);
}

echo "Imported {$result['imported']} rows for chat {$chatId} month {$result['month']} (source: {$result['source']}). Skipped {$result['skipped']} rows.\n";
