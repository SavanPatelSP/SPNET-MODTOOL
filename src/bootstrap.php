<?php

$config = require __DIR__ . '/../config.php';

$timezone = $config['timezone'] ?? 'UTC';
if (!date_default_timezone_set($timezone)) {
    date_default_timezone_set('UTC');
}

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

return $config;
