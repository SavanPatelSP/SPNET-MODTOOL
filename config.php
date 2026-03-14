<?php

$base = require __DIR__ . '/config.example.php';

$localPath = __DIR__ . '/config.local.php';
if (file_exists($localPath)) {
    $local = require $localPath;
    if (is_array($local)) {
        $base = array_replace_recursive($base, $local);
    }
}

// Environment overrides
$envMap = [
    'BOT_TOKEN' => ['bot_token'],
    'BOT_USERNAME' => ['bot_username'],
    'APP_TIMEZONE' => ['timezone'],
    'DB_DSN' => ['db', 'dsn'],
    'DB_USER' => ['db', 'user'],
    'DB_PASS' => ['db', 'pass'],
    'DASHBOARD_TOKEN' => ['dashboard', 'token'],
    'DASHBOARD_CHAT_ID' => ['dashboard', 'default_chat_id'],
    'GSHEETS_WEBHOOK_URL' => ['google_sheets', 'webhook_url'],
    'OWNER_USER_IDS' => ['owner_user_ids'],
];

foreach ($envMap as $envKey => $path) {
    $value = getenv($envKey);
    if ($value === false || $value === '') {
        continue;
    }
    if ($envKey === 'OWNER_USER_IDS') {
        $value = array_values(array_filter(array_map('trim', explode(',', $value))));
    }
    $ref = &$base;
    foreach ($path as $segment) {
        $ref = &$ref[$segment];
    }
    $ref = $value;
}

return $base;
