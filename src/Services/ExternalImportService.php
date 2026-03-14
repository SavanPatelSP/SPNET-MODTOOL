<?php

namespace App\Services;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;

class ExternalImportService
{
    private Database $db;
    private array $config;

    public function __construct(Database $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function importChatkeeper(string $file, int $chatId, ?string $month = null, bool $replace = false): array
    {
        $month = $this->normalizeMonth($month);
        $source = 'chatkeeper';

        if ($replace) {
            $this->db->exec('DELETE FROM external_user_stats WHERE chat_id = ? AND month = ? AND source = ?', [$chatId, $month, $source]);
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'Unable to open file.'];
        }

        $delimiter = ';';
        $enclosure = '"';
        $escape = '\\';
        $header = fgetcsv($handle, 0, $delimiter, $enclosure, $escape);
        if ($header === false) {
            fclose($handle);
            return ['ok' => false, 'error' => 'CSV appears to be empty.'];
        }

        $header = array_map(static fn($value) => trim($value), $header);
        $map = array_flip($header);
        $required = ['Id', 'MessageCount'];
        foreach ($required as $key) {
            if (!isset($map[$key])) {
                fclose($handle);
                return ['ok' => false, 'error' => 'Missing required column: ' . $key];
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        $rows = 0;
        $imported = 0;

        while (($row = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
            $rows++;
            $userId = isset($row[$map['Id']]) ? (int)trim($row[$map['Id']]) : 0;
            if ($userId <= 0) {
                continue;
            }

            $name = isset($map['Name']) ? trim($row[$map['Name']] ?? '') : '';
            $login = isset($map['Login']) ? trim($row[$map['Login']] ?? '') : '';

            $messageCount = isset($map['MessageCount']) ? (int)trim($row[$map['MessageCount']] ?? 0) : 0;
            $replyCount = isset($map['ReplyCount']) ? (int)trim($row[$map['ReplyCount']] ?? 0) : 0;
            $reputationTake = isset($map['ReputationTake']) ? (int)trim($row[$map['ReputationTake']] ?? 0) : 0;

            $username = $login !== '' ? $login : null;
            $firstName = $name !== '' ? $name : null;
            $lastName = null;

            $this->db->exec(
                'INSERT INTO users (id, username, first_name, last_name, is_bot, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name), last_name = VALUES(last_name), updated_at = VALUES(updated_at)',
                [$userId, $username, $firstName, $lastName, $now, $now]
            );

            $this->db->exec(
                'INSERT INTO chat_members (chat_id, user_id, is_mod, created_at, updated_at)
                 VALUES (?, ?, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
                [$chatId, $userId, $now, $now]
            );

            $this->db->exec(
                'INSERT INTO external_user_stats (chat_id, user_id, source, month, messages, replies, reputation_take, warnings, mutes, bans, active_minutes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE messages = VALUES(messages), replies = VALUES(replies), reputation_take = VALUES(reputation_take), updated_at = VALUES(updated_at)',
                [$chatId, $userId, $source, $month, $messageCount, $replyCount, $reputationTake, $now, $now]
            );

            $imported++;
        }

        fclose($handle);

        return ['ok' => true, 'rows' => $rows, 'imported' => $imported, 'month' => $month, 'source' => $source];
    }

    public function importCombot(string $file, int $chatId, ?string $month = null, bool $replace = false): array
    {
        $month = $this->normalizeMonth($month);
        $source = 'combot';

        if ($replace) {
            $this->db->exec('DELETE FROM external_user_stats WHERE chat_id = ? AND month = ? AND source = ?', [$chatId, $month, $source]);
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'Unable to open file.'];
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return ['ok' => false, 'error' => 'CSV appears to be empty.'];
        }

        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        $enclosure = '"';
        $escape = '\\';
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter, $enclosure, $escape);
        if ($header === false) {
            fclose($handle);
            return ['ok' => false, 'error' => 'CSV header missing.'];
        }

        $normalize = static function (string $value): string {
            $value = strtolower(trim($value));
            return preg_replace('/[^a-z0-9]/', '', $value);
        };

        $normalizedHeader = [];
        foreach ($header as $idx => $name) {
            $normalizedHeader[$normalize($name)] = $idx;
        }

        $findColumn = static function (array $normalizedHeader, array $candidates) use ($normalize): ?int {
            foreach ($candidates as $candidate) {
                $key = $normalize($candidate);
                if (isset($normalizedHeader[$key])) {
                    return $normalizedHeader[$key];
                }
            }
            return null;
        };

        $colUserId = $findColumn($normalizedHeader, ['id', 'userid', 'user_id', 'telegramid', 'tgid']);
        $colUsername = $findColumn($normalizedHeader, ['username', 'login', 'user']);
        $colName = $findColumn($normalizedHeader, ['name', 'fullname', 'user_name']);
        $colMessages = $findColumn($normalizedHeader, ['messages', 'messagecount', 'messagescount', 'msgcount', 'msg']);
        $colWarnings = $findColumn($normalizedHeader, ['warnings', 'warns', 'warningcount']);
        $colMutes = $findColumn($normalizedHeader, ['mutes', 'mutecount', 'muted']);
        $colBans = $findColumn($normalizedHeader, ['bans', 'bancount', 'banned']);
        $colActive = $findColumn($normalizedHeader, ['activeminutes', 'active', 'activetime', 'onlinetime', 'timeonline']);

        $parseMinutes = static function ($value): int {
            $value = trim((string)$value);
            if ($value === '') {
                return 0;
            }
            if (is_numeric($value)) {
                return (int)$value;
            }
            if (strpos($value, ':') !== false) {
                [$h, $m] = array_pad(explode(':', $value, 2), 2, 0);
                return ((int)$h * 60) + (int)$m;
            }
            $hours = 0;
            $minutes = 0;
            if (preg_match('/(\\d+)\\s*h/i', $value, $m)) {
                $hours = (int)$m[1];
            }
            if (preg_match('/(\\d+)\\s*m/i', $value, $m)) {
                $minutes = (int)$m[1];
            }
            return ($hours * 60) + $minutes;
        };

        $now = gmdate('Y-m-d H:i:s');
        $rows = 0;
        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
            $rows++;

            $userId = null;
            if ($colUserId !== null && isset($row[$colUserId])) {
                $userId = (int)trim($row[$colUserId]);
            }

            $username = ($colUsername !== null && isset($row[$colUsername])) ? trim($row[$colUsername]) : '';
            $name = ($colName !== null && isset($row[$colName])) ? trim($row[$colName]) : '';

            if (!$userId && $username !== '') {
                $found = $this->db->fetch('SELECT id FROM users WHERE username = ? LIMIT 1', [$username]);
                if ($found) {
                    $userId = (int)$found['id'];
                }
            }

            if (!$userId) {
                $skipped++;
                continue;
            }

            $messageCount = ($colMessages !== null && isset($row[$colMessages])) ? (int)trim($row[$colMessages]) : 0;
            $warningCount = ($colWarnings !== null && isset($row[$colWarnings])) ? (int)trim($row[$colWarnings]) : 0;
            $muteCount = ($colMutes !== null && isset($row[$colMutes])) ? (int)trim($row[$colMutes]) : 0;
            $banCount = ($colBans !== null && isset($row[$colBans])) ? (int)trim($row[$colBans]) : 0;
            $activeMinutes = ($colActive !== null && isset($row[$colActive])) ? $parseMinutes($row[$colActive]) : 0;

            $this->db->exec(
                'INSERT INTO users (id, username, first_name, last_name, is_bot, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name), last_name = VALUES(last_name), updated_at = VALUES(updated_at)',
                [$userId, $username !== '' ? $username : null, $name !== '' ? $name : null, null, $now, $now]
            );

            $this->db->exec(
                'INSERT INTO chat_members (chat_id, user_id, is_mod, created_at, updated_at)
                 VALUES (?, ?, 0, ?, ?)
                 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)',
                [$chatId, $userId, $now, $now]
            );

            $this->db->exec(
                'INSERT INTO external_user_stats (chat_id, user_id, source, month, messages, replies, reputation_take, warnings, mutes, bans, active_minutes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE messages = VALUES(messages), warnings = VALUES(warnings), mutes = VALUES(mutes), bans = VALUES(bans), active_minutes = VALUES(active_minutes), updated_at = VALUES(updated_at)',
                [$chatId, $userId, $source, $month, $messageCount, $warningCount, $muteCount, $banCount, $activeMinutes, $now, $now]
            );

            $imported++;
        }

        fclose($handle);

        return [
            'ok' => true,
            'rows' => $rows,
            'imported' => $imported,
            'skipped' => $skipped,
            'month' => $month,
            'source' => $source,
        ];
    }

    private function normalizeMonth(?string $month): string
    {
        if ($month && preg_match('/^\\d{4}-\\d{2}$/', $month)) {
            return $month;
        }
        $tz = new DateTimeZone($this->config['timezone'] ?? 'UTC');
        return (new DateTimeImmutable('first day of last month', $tz))->format('Y-m');
    }
}
