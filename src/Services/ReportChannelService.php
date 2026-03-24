<?php

namespace App\Services;

use App\Telegram;
use App\Logger;

class ReportChannelService
{
    private Telegram $tg;
    private array $config;
    private int $chunkLimit;

    public function __construct(Telegram $tg, array $config, int $chunkLimit = 3500)
    {
        $this->tg = $tg;
        $this->config = $config;
        $this->chunkLimit = $chunkLimit;
    }

    public function getTargets(): array
    {
        $reports = $this->config['reports'] ?? [];
        $targets = [];

        $channelId = $reports['channel_id'] ?? null;
        if (!empty($channelId)) {
            $targets[] = (int)$channelId;
        }

        $sendManagers = $reports['send_to_managers'] ?? true;
        if ($sendManagers) {
            $owners = $this->config['owner_user_ids'] ?? [];
            $managers = $this->config['manager_user_ids'] ?? [];
            foreach (array_merge((array)$owners, (array)$managers) as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $targets[] = $id;
                }
            }
        }

        $targets = array_values(array_unique(array_filter($targets, static function ($value): bool {
            return (int)$value !== 0;
        })));

        return $targets;
    }

    public function sendMessage(string $text, array $options = [], ?int $limit = null): bool
    {
        $targets = $this->getTargets();
        if (empty($targets)) {
            Logger::info('ReportChannelService: no targets configured.');
            return false;
        }

        $chunks = $this->chunkText($text, $limit ?? $this->chunkLimit);
        $sent = false;

        foreach ($targets as $targetId) {
            foreach ($chunks as $chunk) {
                $resp = $this->tg->sendMessage($targetId, $chunk, $options);
                if (($resp['ok'] ?? false) === true) {
                    $sent = true;
                }
            }
        }

        return $sent;
    }

    public function sendDocument(string $filePath, string $caption = '', array $options = []): bool
    {
        $targets = $this->getTargets();
        if (empty($targets)) {
            Logger::info('ReportChannelService: no targets configured for document.');
            return false;
        }

        $sent = false;
        foreach ($targets as $targetId) {
            $resp = $this->tg->sendDocument($targetId, $filePath, $caption, $options);
            if (($resp['ok'] ?? false) === true) {
                $sent = true;
            }
        }

        return $sent;
    }

    private function chunkText(string $text, int $limit): array
    {
        $lines = explode("\n", $text);
        $chunks = [];
        $current = '';

        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : $current . "\n" . $line;
            if (strlen($candidate) > $limit) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = $line;
                    continue;
                }
                $longParts = str_split($line, $limit);
                foreach ($longParts as $part) {
                    $chunks[] = $part;
                }
                $current = '';
                continue;
            }
            $current = $candidate;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
