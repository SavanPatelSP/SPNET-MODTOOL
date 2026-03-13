<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Telegram;
use App\UpdateHandler;
use App\Logger;

$token = $config['bot_token'] ?? null;
if (!$token || $token === 'YOUR_TELEGRAM_BOT_TOKEN') {
    echo "Missing bot token in config.php\n";
    exit(1);
}

$db = new Database($config['db']);
$tg = new Telegram($token);
$handler = new UpdateHandler($db, $tg, $config);

$offsetFile = __DIR__ . '/../storage/offset.txt';
$offset = 0;
if (file_exists($offsetFile)) {
    $offset = (int)trim(file_get_contents($offsetFile));
}

while (true) {
    $response = $tg->call('getUpdates', [
        'timeout' => 30,
        'offset' => $offset,
        'allowed_updates' => json_encode(['message', 'chat_member']),
    ]);

    if (!($response['ok'] ?? false)) {
        Logger::error('getUpdates failed');
        sleep(2);
        continue;
    }

    foreach ($response['result'] as $update) {
        $offset = $update['update_id'] + 1;
        file_put_contents($offsetFile, (string)$offset);
        $handler->handleUpdate($update);
    }
}
