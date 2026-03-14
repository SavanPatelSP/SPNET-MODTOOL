<?php

namespace App\Services;

use App\Telegram;

class ChangelogService
{
    public function sendIfUpdated(Telegram $tg, array $config, string $context = 'bot'): void
    {
        $logging = $config['logging'] ?? [];
        $channelId = $logging['channel_id'] ?? null;
        $enabled = !empty($logging['log_changelog']);
        if (!$channelId || !$enabled) {
            return;
        }

        $repo = realpath(__DIR__ . '/../../');
        if (!$repo || !is_dir($repo . '/.git')) {
            return;
        }

        if (!function_exists('shell_exec')) {
            return;
        }

        $head = trim((string)shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse HEAD 2>/dev/null'));
        if ($head === '') {
            return;
        }

        $stateFile = $repo . '/storage/logs/last_changelog.txt';
        $last = '';
        if (file_exists($stateFile)) {
            $last = trim((string)@file_get_contents($stateFile));
        }

        if ($last === $head) {
            return;
        }

        $log = '';
        if ($last !== '') {
            $log = trim((string)shell_exec('git -C ' . escapeshellarg($repo) . ' log --pretty=format:"%h %s" ' . escapeshellarg($last . '..' . $head) . ' 2>/dev/null'));
        }
        if ($log === '') {
            $log = trim((string)shell_exec('git -C ' . escapeshellarg($repo) . ' log -5 --pretty=format:"%h %s" 2>/dev/null'));
        }

        if ($log === '') {
            return;
        }

        $lines = explode("\n", $log);
        $message = '<b>Bot updated</b> (' . htmlspecialchars($context, ENT_QUOTES, 'UTF-8') . ')' . "\n" . implode("\n", array_map('htmlspecialchars', $lines));

        $tg->sendMessage($channelId, $message, [
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        @file_put_contents($stateFile, $head);
    }
}
