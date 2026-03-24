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
        $meta = [];
        if (array_key_exists('report_meta', $options)) {
            $meta = is_array($options['report_meta']) ? $options['report_meta'] : [];
            unset($options['report_meta']);
        }
        $targets = $this->getTargets();
        if (empty($targets)) {
            Logger::info('ReportChannelService: no targets configured.');
            return false;
        }

        $sent = false;

        foreach ($targets as $targetId) {
            $body = $text;
            if ($this->shouldDetail($targetId, $meta)) {
                $body = $this->buildReportHeader($meta) . "\n" . $text;
            }
            $chunks = $this->chunkText($body, $limit ?? $this->chunkLimit);
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
        $meta = [];
        if (array_key_exists('report_meta', $options)) {
            $meta = is_array($options['report_meta']) ? $options['report_meta'] : [];
            unset($options['report_meta']);
        }
        $targets = $this->getTargets();
        if (empty($targets)) {
            Logger::info('ReportChannelService: no targets configured for document.');
            return false;
        }

        $sent = false;
        foreach ($targets as $targetId) {
            if ($this->shouldDetail($targetId, $meta)) {
                $header = $this->buildReportHeader($meta);
                $this->tg->sendMessage($targetId, $header, ['parse_mode' => 'HTML']);
            }
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

    private function shouldDetail(int $targetId, array $meta): bool
    {
        if (empty($meta)) {
            return false;
        }
        if ($targetId >= 0) {
            return false;
        }
        $reports = $this->config['reports'] ?? [];
        return (bool)($reports['detailed_channel'] ?? true);
    }

    private function buildReportHeader(array $meta): string
    {
        $reports = $this->config['reports'] ?? [];
        $brand = $reports['brand_name'] ?? ($this->config['report']['brand_name'] ?? 'SP NET MOD TOOL');
        $reportType = $meta['report_type'] ?? 'Report';
        $chatId = $meta['chat_id'] ?? null;
        $chatTitle = $meta['chat_title'] ?? null;
        $period = $meta['period'] ?? null;
        $budget = $meta['budget'] ?? null;
        $timezone = $meta['timezone'] ?? ($this->config['timezone'] ?? 'UTC');
        $reportId = $meta['report_id'] ?? $this->buildReportId($meta);

        $lines = [];
        $lines[] = '<b>' . $this->escape((string)$brand) . '</b> · ' . $this->escape((string)$reportType);
        if ($chatId !== null) {
            $title = $chatTitle ?: ('Chat ' . $chatId);
            $lines[] = 'Chat: ' . $this->escape((string)$title) . ' (' . $chatId . ')';
        } elseif ($chatTitle) {
            $lines[] = 'Scope: ' . $this->escape((string)$chatTitle);
        }
        if ($period) {
            $lines[] = 'Period: ' . $this->escape((string)$period);
        }
        if ($budget !== null) {
            $lines[] = 'Budget: ' . number_format((float)$budget, 2);
        }
        $lines[] = 'Report ID: ' . $this->escape($reportId) . ' · Generated: ' . $this->escape($this->formatNow($timezone));

        return implode("\n", $lines);
    }

    private function buildReportId(array $meta): string
    {
        $seed = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $hash = substr(sha1((string)$seed), 0, 6);
        return 'RPT-' . gmdate('Ymd-His') . '-' . strtoupper($hash);
    }

    private function formatNow(string $timezone): string
    {
        try {
            $dt = new \DateTimeImmutable('now', new \DateTimeZone($timezone));
            return $dt->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            return gmdate('Y-m-d H:i');
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
