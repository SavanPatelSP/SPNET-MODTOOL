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

        $weeklyDefaults = $this->config['weekly_summary_defaults'] ?? [];
        $weeklyEnabled = !empty($weeklyDefaults['enabled']) ? 1 : 0;
        $weeklyWeekday = (int)($weeklyDefaults['weekday'] ?? 1);
        $weeklyHour = (int)($weeklyDefaults['hour'] ?? 10);

        $inactiveDefaults = $this->config['inactivity_alert_defaults'] ?? [];
        $inactiveEnabled = !empty($inactiveDefaults['enabled']) ? 1 : 0;
        $inactiveDays = (int)($inactiveDefaults['days'] ?? 7);
        $inactiveHour = (int)($inactiveDefaults['hour'] ?? 10);

        $aiDefaults = $this->config['ai_review_defaults'] ?? [];
        $aiEnabled = !empty($aiDefaults['enabled']) ? 1 : 0;
        $aiDay = (int)($aiDefaults['day'] ?? 1);
        $aiHour = (int)($aiDefaults['hour'] ?? 9);
        $approvals = $this->config['approvals'] ?? [];
        $approvalRequired = !empty($approvals['default_required']) ? 1 : 0;

        $this->db->exec(
            'INSERT INTO settings (chat_id, reward_budget, timezone, active_gap_minutes, active_floor_minutes, auto_report_enabled, auto_report_day, auto_report_hour, progress_report_enabled, progress_report_day, progress_report_hour, weekly_summary_enabled, weekly_summary_weekday, weekly_summary_hour, inactivity_alert_enabled, inactivity_alert_days, inactivity_alert_hour, ai_review_enabled, ai_review_day, ai_review_hour, approval_required, updated_at)
             VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$chatId, $timezone, $gap, $floor, $autoEnabled, $autoDay, $autoHour, $progressEnabled, $progressDay, $progressHour, $weeklyEnabled, $weeklyWeekday, $weeklyHour, $inactiveEnabled, $inactiveDays, $inactiveHour, $aiEnabled, $aiDay, $aiHour, $approvalRequired, $this->nowUtc()]
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
            'weekly_summary_enabled' => $weeklyEnabled,
            'weekly_summary_weekday' => $weeklyWeekday,
            'weekly_summary_hour' => $weeklyHour,
            'inactivity_alert_enabled' => $inactiveEnabled,
            'inactivity_alert_days' => $inactiveDays,
            'inactivity_alert_hour' => $inactiveHour,
            'ai_review_enabled' => $aiEnabled,
            'ai_review_day' => $aiDay,
            'ai_review_hour' => $aiHour,
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

    public function updateWeeklySummary(int|string $chatId, bool $enabled, ?int $weekday, ?int $hour): void
    {
        $fields = ['weekly_summary_enabled = ?', 'updated_at = ?'];
        $params = [$enabled ? 1 : 0, $this->nowUtc()];

        if ($weekday !== null) {
            $fields[] = 'weekly_summary_weekday = ?';
            $params[] = $weekday;
        }
        if ($hour !== null) {
            $fields[] = 'weekly_summary_hour = ?';
            $params[] = $hour;
        }

        $params[] = $chatId;
        $sql = 'UPDATE settings SET ' . implode(', ', $fields) . ' WHERE chat_id = ?';
        $this->db->exec($sql, $params);
    }

    public function updateWeeklySummaryLast(int|string $chatId, string $weekKey): void
    {
        $this->db->exec(
            'UPDATE settings SET weekly_summary_last_week = ?, weekly_summary_last_sent_at = ?, updated_at = ? WHERE chat_id = ?',
            [$weekKey, $this->nowUtc(), $this->nowUtc(), $chatId]
        );
    }

    public function updateInactivityAlert(int|string $chatId, bool $enabled, ?int $days, ?int $hour): void
    {
        $fields = ['inactivity_alert_enabled = ?', 'updated_at = ?'];
        $params = [$enabled ? 1 : 0, $this->nowUtc()];

        if ($days !== null) {
            $fields[] = 'inactivity_alert_days = ?';
            $params[] = $days;
        }
        if ($hour !== null) {
            $fields[] = 'inactivity_alert_hour = ?';
            $params[] = $hour;
        }

        $params[] = $chatId;
        $sql = 'UPDATE settings SET ' . implode(', ', $fields) . ' WHERE chat_id = ?';
        $this->db->exec($sql, $params);
    }

    public function updateAiReview(int|string $chatId, bool $enabled, ?int $day, ?int $hour): void
    {
        $fields = ['ai_review_enabled = ?', 'updated_at = ?'];
        $params = [$enabled ? 1 : 0, $this->nowUtc()];

        if ($day !== null) {
            $fields[] = 'ai_review_day = ?';
            $params[] = $day;
        }
        if ($hour !== null) {
            $fields[] = 'ai_review_hour = ?';
            $params[] = $hour;
        }

        $params[] = $chatId;
        $sql = 'UPDATE settings SET ' . implode(', ', $fields) . ' WHERE chat_id = ?';
        $this->db->exec($sql, $params);
    }

    public function updateAiReviewLast(int|string $chatId, string $month): void
    {
        $this->db->exec(
            'UPDATE settings SET ai_review_last_month = ?, ai_review_last_sent_at = ?, updated_at = ? WHERE chat_id = ?',
            [$month, $this->nowUtc(), $this->nowUtc(), $chatId]
        );
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
