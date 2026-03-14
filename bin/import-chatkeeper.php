<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;

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
if (!$month) {
    $tz = new \DateTimeZone($config['timezone'] ?? 'UTC');
    $month = (new \DateTimeImmutable('first day of last month', $tz))->format('Y-m');
}

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo "Invalid --month format. Use YYYY-MM.\n";
    exit(1);
}

$source = 'chatkeeper';
if (isset($opts['replace'])) {
    $db->exec('DELETE FROM external_user_stats WHERE chat_id = ? AND month = ? AND source = ?', [$chatId, $month, $source]);
}

$handle = fopen($file, 'r');
if ($handle === false) {
    echo "Unable to open file.\n";
    exit(1);
}

$delimiter = ';';
$enclosure = '"';
$escape = '\\';
$header = fgetcsv($handle, 0, $delimiter, $enclosure, $escape);
if ($header === false) {
    echo "CSV appears to be empty.\n";
    exit(1);
}

$header = array_map(static function ($value) {
    return trim($value);
}, $header);

$map = array_flip($header);
$required = ['Id', 'MessageCount'];
foreach ($required as $key) {
    if (!isset($map[$key])) {
        echo "Missing required column: {$key}\n";
        exit(1);
    }
}

$now = gmdate('Y-m-d H:i:s');
$rows = 0;
$imported = 0;

while (($row = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
    $rows++;
    $userId = isset($row[$map['Id']]) ? (int)trim($row[$map['Id']]) : 0;
    if ($userId <= 0) {
        continue;
    }

    $name = isset($map['Name']) ? trim($row[$map['Name']] ?? '') : '';
    $login = isset($map['Login']) ? trim($row[$map['Login']] ?? '') : '';

    $messageCount = isset($map['MessageCount']) ? (int)trim($row[$map['MessageCount']] ?? 0) : 0;
    $replyCount = isset($map['ReplyCount']) ? (int)trim($row[$map['ReplyCount']] ?? 0) : 0;
    $reputationTake = isset($map['ReputationTake']) ? (int)trim($row[$map['ReputationTake']] ?? 0) : 0;

    $username = $login !== '' ? $login : null;
    $firstName = $name !== '' ? $name : null;
    $lastName = null;

    $db->exec(
        'INSERT INTO users (id, username, first_name, last_name, is_bot, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, ?, ?)
         ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name), last_name = VALUES(last_name), updated_at = VALUES(updated_at)',
        [$userId, $username, $firstName, $lastName, $now, $now]
    );

    $db->exec(
        'INSERT INTO chat_members (chat_id, user_id, is_mod, created_at, updated_at)
         VALUES (?, ?, 0, ?, ?)
         ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
        [$chatId, $userId, $now, $now]
    );

    $db->exec(
        'INSERT INTO external_user_stats (chat_id, user_id, source, month, messages, replies, reputation_take, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE messages = VALUES(messages), replies = VALUES(replies), reputation_take = VALUES(reputation_take), updated_at = VALUES(updated_at)',
        [$chatId, $userId, $source, $month, $messageCount, $replyCount, $reputationTake, $now, $now]
    );

    $imported++;
}

fclose($handle);

echo "Imported {$imported} rows for chat {$chatId} month {$month} (source: {$source}).\n";
