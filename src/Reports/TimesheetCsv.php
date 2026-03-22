<?php

namespace App\Reports;

use App\Services\StatsService;
use DateTimeImmutable;

class TimesheetCsv
{
    private StatsService $stats;

    public function __construct(StatsService $stats)
    {
        $this->stats = $stats;
    }

    public function generate(int|string $chatId, int|string $userId, string $displayName, DateTimeImmutable $startLocal, DateTimeImmutable $endLocal): string
    {
        $bundle = $this->stats->getTimesheet($chatId, $userId, $startLocal, $endLocal);
        $range = $bundle['range'] ?? [];
        $days = $bundle['days'] ?? [];
        $totals = $bundle['totals'] ?? [];

        $safeStart = $range['start_local'] instanceof DateTimeImmutable ? $range['start_local']->format('Y-m-d') : 'start';
        $safeEnd = $range['end_local'] instanceof DateTimeImmutable ? $range['end_local']->format('Y-m-d') : 'end';
        $file = __DIR__ . '/../../storage/reports/timesheet-' . $chatId . '-' . $userId . '-' . $safeStart . '-' . $safeEnd . '.csv';

        $fp = fopen($file, 'w');
        fputcsv($fp, ['Timesheet', $displayName]);
        fputcsv($fp, ['Range', $range['label'] ?? ($safeStart . ' to ' . $safeEnd)]);
        fputcsv($fp, ['Timezone', $bundle['timezone'] ?? 'UTC']);
        fputcsv($fp, []);

        fputcsv($fp, ['Date', 'Messages', 'Active Minutes', 'Active Hours', 'Presence Minutes', 'Presence Hours']);

        foreach ($days as $day) {
            $activeMinutes = (float)($day['active_minutes'] ?? 0);
            $presenceMinutes = (float)($day['presence_minutes'] ?? 0);
            fputcsv($fp, [
                $day['date'] ?? '',
                (int)($day['messages'] ?? 0),
                number_format($activeMinutes, 2, '.', ''),
                number_format($activeMinutes / 60, 2, '.', ''),
                number_format($presenceMinutes, 2, '.', ''),
                number_format($presenceMinutes / 60, 2, '.', ''),
            ]);
        }

        fputcsv($fp, []);
        fputcsv($fp, [
            'TOTAL',
            (int)($totals['messages'] ?? 0),
            number_format((float)($totals['active_minutes'] ?? 0), 2, '.', ''),
            number_format(((float)($totals['active_minutes'] ?? 0)) / 60, 2, '.', ''),
            number_format((float)($totals['presence_minutes'] ?? 0), 2, '.', ''),
            number_format(((float)($totals['presence_minutes'] ?? 0)) / 60, 2, '.', ''),
        ]);

        fclose($fp);
        return realpath($file) ?: $file;
    }
}
