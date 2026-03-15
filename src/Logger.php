<?php

namespace App;

use App\Telegram;

class Logger
{
    private static ?Telegram $tg = null;
    private static ?string $channelId = null;
    private static string $minLevel = 'info';
    private static int $maxLength = 3500;
    private static bool $channelEnabled = false;
    private static bool $sending = false;
    private static string $timezoneName = 'UTC';

    public static function initChannel(?Telegram $tg, array $config): void
    {
        $logging = $config['logging'] ?? [];
        $channelId = $logging['channel_id'] ?? null;
        if (!$tg || !$channelId) {
            self::$channelEnabled = false;
            return;
        }

        self::$tg = $tg;
        self::$channelId = (string)$channelId;
        self::$minLevel = strtolower((string)($logging['min_level'] ?? 'info'));
        self::$maxLength = max(500, (int)($logging['max_length'] ?? 3500));
        self::$timezoneName = (string)($config['timezone'] ?? date_default_timezone_get() ?: 'UTC');
        self::$channelEnabled = true;
    }

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
        $date = self::formatTimestamp();
        $line = '[' . $date . '] ' . $level . ' ' . $message . PHP_EOL;
        @file_put_contents($path, $line, FILE_APPEND);

        self::sendToChannel($level, $message, $date);
    }

    private static function sendToChannel(string $level, string $message, string $date): void
    {
        if (!self::$channelEnabled || !self::$tg || !self::$channelId) {
            return;
        }
        if (!self::levelAllowed($level)) {
            return;
        }
        if (self::$sending) {
            return;
        }

        self::$sending = true;
        $text = '[' . $date . '] ' . $level . "\n" . $message;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) > self::$maxLength) {
                $text = mb_substr($text, 0, self::$maxLength - 3) . '...';
            }
        } elseif (strlen($text) > self::$maxLength) {
            $text = substr($text, 0, self::$maxLength - 3) . '...';
        }

        $safeText = function_exists('htmlspecialchars')
            ? htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            : $text;

        self::$tg->sendMessage(self::$channelId, $safeText, [
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
        self::$sending = false;
    }

    private static function formatTimestamp(): string
    {
        try {
            $tzName = self::$timezoneName !== '' ? self::$timezoneName : 'UTC';
            $tz = new \DateTimeZone($tzName);
            $dt = new \DateTimeImmutable('now', $tz);
            return $dt->format('Y-m-d H:i:s.v T P') . ' ' . $tzName;
        } catch (\Throwable $e) {
            return gmdate('Y-m-d H:i:s') . ' UTC +00:00 UTC';
        }
    }

    private static function levelAllowed(string $level): bool
    {
        $level = strtoupper($level);
        $min = strtoupper(self::$minLevel);
        if ($min === 'ERROR') {
            return $level === 'ERROR';
        }
        return true;
    }
}
