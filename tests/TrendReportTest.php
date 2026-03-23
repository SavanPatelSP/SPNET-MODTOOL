<?php

namespace Tests;

use App\Reports\TrendReport;
use App\Services\RewardService;
use App\Services\StatsService;
use PHPUnit\Framework\TestCase;

class TrendReportTest extends TestCase
{
    private string $reportDir;

    protected function setUp(): void
    {
        $this->reportDir = dirname(__DIR__) . '/storage/reports';
        if (!is_dir($this->reportDir)) {
            mkdir($this->reportDir, 0777, true);
        }
    }

    public function testTrendReportGeneratesHtml(): void
    {
        $config = require __DIR__ . '/../config.example.php';
        $service = new RewardService($config);

        $bundleCurrent = [
            'mods' => [
                [
                    'user_id' => 1,
                    'display_name' => 'Alpha',
                    'messages' => 120,
                    'warnings' => 2,
                    'mutes' => 0,
                    'bans' => 0,
                    'actions_total' => 2,
                    'active_minutes' => 600,
                    'membership_minutes' => 900,
                    'days_active' => 12,
                    'score' => 55,
                    'impact_score' => 12,
                    'consistency_index' => 75,
                    'role_multiplier' => 1.0,
                    'improvement' => 5,
                ],
            ],
            'summary' => [
                'messages' => 120,
                'warnings' => 2,
                'mutes' => 0,
                'bans' => 0,
                'active_minutes' => 600,
                'avg_score' => 55,
            ],
            'range' => [
                'label' => 'February 2026',
                'month' => '2026-02',
            ],
        ];

        $bundlePrev = [
            'mods' => $bundleCurrent['mods'],
            'summary' => [
                'messages' => 90,
                'warnings' => 1,
                'mutes' => 0,
                'bans' => 0,
                'active_minutes' => 400,
                'avg_score' => 45,
            ],
            'range' => [
                'label' => 'January 2026',
                'month' => '2026-01',
            ],
        ];

        $stats = $this->createMock(StatsService::class);
        $stats->method('getMonthlyStats')->willReturnOnConsecutiveCalls($bundleCurrent, $bundlePrev);

        $report = new TrendReport($stats, $service, $config);
        $file = $report->generate(123, '2026-02', 100.0);

        $this->assertFileExists($file);
        $html = file_get_contents($file);
        $this->assertStringContainsString('Trend Report', $html);
    }
}
