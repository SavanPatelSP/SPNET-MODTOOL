<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;

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
if (!$month) {
    $tz = new \DateTimeZone($config['timezone'] ?? 'UTC');
    $month = (new \DateTimeImmutable('first day of last month', $tz))->format('Y-m');
}

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo "Invalid --month format. Use YYYY-MM.\n";
    exit(1);
}

$source = 'combot';
if (isset($opts['replace'])) {
    $db->exec('DELETE FROM external_user_stats WHERE chat_id = ? AND month = ? AND source = ?', [$chatId, $month, $source]);
}

$handle = fopen($file, 'r');
if ($handle === false) {
    echo "Unable to open file.\n";
    exit(1);
}

$firstLine = fgets($handle);
if ($firstLine === false) {
    echo "CSV appears to be empty.\n";
    exit(1);
}

$delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
$enclosure = '"';
$escape = '\\';
rewind($handle);

$header = fgetcsv($handle, 0, $delimiter, $enclosure, $escape);
if ($header === false) {
    echo "CSV header missing.\n";
    exit(1);
}

$normalize = static function (string $value): string {
    $value = strtolower(trim($value));
    return preg_replace('/[^a-z0-9]/', '', $value);
};

$normalizedHeader = [];
foreach ($header as $idx => $name) {
    $normalizedHeader[$normalize($name)] = $idx;
}

$findColumn = static function (array $normalizedHeader, array $candidates) use ($normalize): ?int {
    foreach ($candidates as $candidate) {
        $key = $normalize($candidate);
        if (isset($normalizedHeader[$key])) {
            return $normalizedHeader[$key];
        }
    }
    return null;
};

$colUserId = $findColumn($normalizedHeader, ['id', 'userid', 'user_id', 'telegramid', 'tgid']);
$colUsername = $findColumn($normalizedHeader, ['username', 'login', 'user']);
$colName = $findColumn($normalizedHeader, ['name', 'fullname', 'user_name']);
$colMessages = $findColumn($normalizedHeader, ['messages', 'messagecount', 'messagescount', 'msgcount', 'msg']);
$colWarnings = $findColumn($normalizedHeader, ['warnings', 'warns', 'warningcount']);
$colMutes = $findColumn($normalizedHeader, ['mutes', 'mutecount', 'muted']);
$colBans = $findColumn($normalizedHeader, ['bans', 'bancount', 'banned']);
$colActive = $findColumn($normalizedHeader, ['activeminutes', 'active', 'activetime', 'onlinetime', 'timeonline']);

$parseMinutes = static function ($value): int {
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    if (strpos($value, ':') !== false) {
        [$h, $m] = array_pad(explode(':', $value, 2), 2, 0);
        return ((int)$h * 60) + (int)$m;
    }
    $hours = 0;
    $minutes = 0;
    if (preg_match('/(\d+)\s*h/i', $value, $m)) {
        $hours = (int)$m[1];
    }
    if (preg_match('/(\d+)\s*m/i', $value, $m)) {
        $minutes = (int)$m[1];
    }
    return ($hours * 60) + $minutes;
};

$now = gmdate('Y-m-d H:i:s');
$rows = 0;
$imported = 0;
$skipped = 0;

while (($row = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
    $rows++;

    $userId = null;
    if ($colUserId !== null && isset($row[$colUserId])) {
        $userId = (int)trim($row[$colUserId]);
    }

    $username = ($colUsername !== null && isset($row[$colUsername])) ? trim($row[$colUsername]) : '';
    $name = ($colName !== null && isset($row[$colName])) ? trim($row[$colName]) : '';

    if (!$userId && $username !== '') {
        $found = $db->fetch('SELECT id FROM users WHERE username = ? LIMIT 1', [$username]);
        if ($found) {
            $userId = (int)$found['id'];
        }
    }

    if (!$userId) {
        $skipped++;
        continue;
    }

    $messageCount = ($colMessages !== null && isset($row[$colMessages])) ? (int)trim($row[$colMessages]) : 0;
    $warningCount = ($colWarnings !== null && isset($row[$colWarnings])) ? (int)trim($row[$colWarnings]) : 0;
    $muteCount = ($colMutes !== null && isset($row[$colMutes])) ? (int)trim($row[$colMutes]) : 0;
    $banCount = ($colBans !== null && isset($row[$colBans])) ? (int)trim($row[$colBans]) : 0;
    $activeMinutes = ($colActive !== null && isset($row[$colActive])) ? $parseMinutes($row[$colActive]) : 0;

    $db->exec(
        'INSERT INTO users (id, username, first_name, last_name, is_bot, created_at, updated_at)
         VALUES (?, ?, ?, ?, 0, ?, ?)
         ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name), last_name = VALUES(last_name), updated_at = VALUES(updated_at)',
        [$userId, $username !== '' ? $username : null, $name !== '' ? $name : null, null, $now, $now]
    );

    $db->exec(
        'INSERT INTO chat_members (chat_id, user_id, is_mod, created_at, updated_at)
         VALUES (?, ?, 0, ?, ?)
         ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
        [$chatId, $userId, $now, $now]
    );

    $db->exec(
        'INSERT INTO external_user_stats (chat_id, user_id, source, month, messages, replies, reputation_take, warnings, mutes, bans, active_minutes, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE messages = VALUES(messages), warnings = VALUES(warnings), mutes = VALUES(mutes), bans = VALUES(bans), active_minutes = VALUES(active_minutes), updated_at = VALUES(updated_at)',
        [$chatId, $userId, $source, $month, $messageCount, $warningCount, $muteCount, $banCount, $activeMinutes, $now, $now]
    );

    $imported++;
}

fclose($handle);

echo "Imported {$imported} rows for chat {$chatId} month {$month} (source: {$source}). Skipped {$skipped} rows.\n";
