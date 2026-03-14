<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Telegram;
use App\UpdateHandler;
use App\Logger;
use App\Services\ChangelogService;

$token = $config['bot_token'] ?? null;
if (!$token || $token === 'YOUR_TELEGRAM_BOT_TOKEN') {
    echo "Missing bot token in config.php\n";
    exit(1);
}

$db = new Database($config['db']);
$tg = new Telegram($token, $config['telegram'] ?? []);
$loggerConfig = $config['logging'] ?? [];
Logger::initChannel($tg, $config);
$changelog = new ChangelogService();
$changelog->sendIfUpdated($tg, $config, 'poller');
$handler = new UpdateHandler($db, $tg, $config);

$polling = $config['polling'] ?? [];
$timeout = max(1, (int)($polling['timeout_seconds'] ?? 10));
$limit = max(1, min(100, (int)($polling['limit'] ?? 50)));
$sleepMs = max(0, (int)($polling['sleep_ms'] ?? 0));
$errorSleepMs = max(100, (int)($polling['error_sleep_ms'] ?? 1000));
$allowedUpdates = $polling['allowed_updates'] ?? ['message', 'chat_member'];

$offsetFile = __DIR__ . '/../storage/offset.txt';
$offset = 0;
if (file_exists($offsetFile)) {
    $offset = (int)trim(file_get_contents($offsetFile));
}

while (true) {
    $response = $tg->call('getUpdates', [
        'timeout' => $timeout,
        'limit' => $limit,
        'offset' => $offset,
        'allowed_updates' => json_encode($allowedUpdates),
    ]);

    if (!($response['ok'] ?? false)) {
        Logger::error('getUpdates failed');
        usleep($errorSleepMs * 1000);
        continue;
    }

    foreach ($response['result'] as $update) {
        $offset = $update['update_id'] + 1;
        file_put_contents($offsetFile, (string)$offset);
        $handler->handleUpdate($update);
    }

    if ($sleepMs > 0) {
        usleep($sleepMs * 1000);
    }
}
