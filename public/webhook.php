<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Telegram;
use App\UpdateHandler;

$token = $config['bot_token'] ?? null;
if (!$token || $token === 'YOUR_TELEGRAM_BOT_TOKEN') {
    http_response_code(500);
    echo 'Missing bot token.';
    exit;
}

$input = file_get_contents('php://input');
$update = json_decode($input, true);
if (!$update) {
    http_response_code(400);
    echo 'Invalid payload.';
    exit;
}

$db = new Database($config['db']);
$tg = new Telegram($token);
$handler = new UpdateHandler($db, $tg, $config);
$handler->handleUpdate($update);

echo 'OK';
