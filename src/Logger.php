<?php

namespace App;

class Logger
{
    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    private static function write(string $level, string $message): void
    {
        $path = __DIR__ . '/../storage/logs/app.log';
        $date = gmdate('Y-m-d H:i:s');
        $line = '[' . $date . '] ' . $level . ' ' . $message . PHP_EOL;
        @file_put_contents($path, $line, FILE_APPEND);
    }
}
