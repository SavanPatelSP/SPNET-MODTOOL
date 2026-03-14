<?php

namespace App\Services;

use App\Database;

class SettingsService
{
    private Database $db;
    private array $config;

    public function __construct(Database $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    public function get(int|string $chatId): array
    {
        $row = $this->db->fetch('SELECT * FROM settings WHERE chat_id = ?', [$chatId]);
        if ($row) {
            return $row;
        }

        $timezone = $this->config['timezone'] ?? 'UTC';
        $gap = (int)($this->config['active_gap_minutes'] ?? 5);
        $floor = (int)($this->config['active_floor_minutes'] ?? 1);
        $autoDefaults = $this->config['auto_report_defaults'] ?? [];
        $autoEnabled = !empty($autoDefaults['enabled']) ? 1 : 0;
        $autoDay = (int)($autoDefaults['day'] ?? 1);
        $autoHour = (int)($autoDefaults['hour'] ?? 9);

        $progressDefaults = $this->config['progress_report_defaults'] ?? [];
        $progressEnabled = !empty($progressDefaults['enabled']) ? 1 : 0;
        $progressDay = (int)($progressDefaults['day'] ?? 15);
        $progressHour = (int)($progressDefaults['hour'] ?? 12);
        $approvals = $this->config['approvals'] ?? [];
        $approvalRequired = !empty($approvals['default_required']) ? 1 : 0;

        $this->db->exec(
            'INSERT INTO settings (chat_id, reward_budget, timezone, active_gap_minutes, active_floor_minutes, auto_report_enabled, auto_report_day, auto_report_hour, progress_report_enabled, progress_report_day, progress_report_hour, approval_required, updated_at)
             VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$chatId, $timezone, $gap, $floor, $autoEnabled, $autoDay, $autoHour, $progressEnabled, $progressDay, $progressHour, $approvalRequired, $this->nowUtc()]
        );

        return [
            'chat_id' => $chatId,
            'reward_budget' => 0,
            'timezone' => $timezone,
            'active_gap_minutes' => $gap,
            'active_floor_minutes' => $floor,
            'auto_report_enabled' => $autoEnabled,
            'auto_report_day' => $autoDay,
            'auto_report_hour' => $autoHour,
            'progress_report_enabled' => $progressEnabled,
            'progress_report_day' => $progressDay,
            'progress_report_hour' => $progressHour,
            'approval_required' => $approvalRequired,
        ];
    }

    public function updateApprovalRequired(int|string $chatId, bool $required): void
    {
        $this->db->exec(
            'UPDATE settings SET approval_required = ?, updated_at = ? WHERE chat_id = ?',
            [$required ? 1 : 0, $this->nowUtc(), $chatId]
        );
    }

    public function updateBudget(int|string $chatId, float $budget): void
    {
        $this->db->exec(
            'UPDATE settings SET reward_budget = ?, updated_at = ? WHERE chat_id = ?',
            [$budget, $this->nowUtc(), $chatId]
        );
    }

    public function updateTimezone(int|string $chatId, string $timezone): void
    {
        $this->db->exec(
            'UPDATE settings SET timezone = ?, updated_at = ? WHERE chat_id = ?',
            [$timezone, $this->nowUtc(), $chatId]
        );
    }

    public function updateActivitySettings(int|string $chatId, int $gapMinutes, int $floorMinutes): void
    {
        $this->db->exec(
            'UPDATE settings SET active_gap_minutes = ?, active_floor_minutes = ?, updated_at = ? WHERE chat_id = ?',
            [$gapMinutes, $floorMinutes, $this->nowUtc(), $chatId]
        );
    }

    public function updateAutoReport(int|string $chatId, bool $enabled, ?int $day, ?int $hour): void
    {
        $fields = ['auto_report_enabled = ?', 'updated_at = ?'];
        $params = [$enabled ? 1 : 0, $this->nowUtc()];

        if ($day !== null) {
            $fields[] = 'auto_report_day = ?';
            $params[] = $day;
        }
        if ($hour !== null) {
            $fields[] = 'auto_report_hour = ?';
            $params[] = $hour;
        }

        $params[] = $chatId;
        $sql = 'UPDATE settings SET ' . implode(', ', $fields) . ' WHERE chat_id = ?';
        $this->db->exec($sql, $params);
    }

    public function updateAutoReportLast(int|string $chatId, string $month): void
    {
        $this->db->exec(
            'UPDATE settings SET auto_report_last_month = ?, auto_report_last_sent_at = ?, updated_at = ? WHERE chat_id = ?',
            [$month, $this->nowUtc(), $this->nowUtc(), $chatId]
        );
    }

    public function updateProgressReport(int|string $chatId, bool $enabled, ?int $day, ?int $hour): void
    {
        $fields = ['progress_report_enabled = ?', 'updated_at = ?'];
        $params = [$enabled ? 1 : 0, $this->nowUtc()];

        if ($day !== null) {
            $fields[] = 'progress_report_day = ?';
            $params[] = $day;
        }
        if ($hour !== null) {
            $fields[] = 'progress_report_hour = ?';
            $params[] = $hour;
        }

        $params[] = $chatId;
        $sql = 'UPDATE settings SET ' . implode(', ', $fields) . ' WHERE chat_id = ?';
        $this->db->exec($sql, $params);
    }

    public function updateProgressReportLast(int|string $chatId, string $month): void
    {
        $this->db->exec(
            'UPDATE settings SET progress_report_last_month = ?, progress_report_last_sent_at = ?, updated_at = ? WHERE chat_id = ?',
            [$month, $this->nowUtc(), $this->nowUtc(), $chatId]
        );
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
