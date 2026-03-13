<?php

namespace App\Services;

use App\Logger;

class GoogleSheetsService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function export(array $payload): array
    {
        $settings = $this->config['google_sheets'] ?? [];
        $url = $settings['webhook_url'] ?? null;
        if (!$url) {
            return ['ok' => false, 'error' => 'Google Sheets webhook_url not configured.'];
        }

        $timeout = (int)($settings['timeout_seconds'] ?? 10);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $result = curl_exec($ch);
        if ($result === false) {
            Logger::error('Google Sheets export failed: ' . curl_error($ch));
            curl_close($ch);
            return ['ok' => false, 'error' => 'Request failed'];
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'Webhook returned HTTP ' . $status, 'response' => $result];
        }

        return ['ok' => true, 'response' => $result];
    }
}
