<?php

namespace Tests;

use App\Reports\RewardSheet;
use App\Services\RewardService;
use App\Services\StatsService;
use PHPUnit\Framework\TestCase;

class RewardSheetTest extends TestCase
{
    private string $reportDir;

    protected function setUp(): void
    {
        $this->reportDir = dirname(__DIR__) . '/storage/reports';
        if (!is_dir($this->reportDir)) {
            mkdir($this->reportDir, 0777, true);
        }
    }

    public function testRewardSheetContainsBadges(): void
    {
        $config = require __DIR__ . '/../config.example.php';
        $service = new RewardService($config);

        $bundle = [
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
                [
                    'user_id' => 2,
                    'display_name' => 'Bravo',
                    'messages' => 60,
                    'warnings' => 1,
                    'mutes' => 0,
                    'bans' => 0,
                    'actions_total' => 1,
                    'active_minutes' => 300,
                    'membership_minutes' => 600,
                    'days_active' => 8,
                    'score' => 28,
                    'impact_score' => 7,
                    'consistency_index' => 60,
                    'role_multiplier' => 1.0,
                    'improvement' => 2,
                ],
            ],
            'summary' => [
                'messages' => 180,
                'warnings' => 3,
                'mutes' => 0,
                'bans' => 0,
                'active_minutes' => 900,
                'avg_score' => 41.5,
                'total_mods' => 2,
                'avg_impact' => 9.5,
                'avg_consistency' => 67.5,
            ],
            'range' => [
                'label' => 'February 2026',
                'month' => '2026-02',
            ],
            'settings' => [
                'reward_budget' => 100,
            ],
        ];

        $stats = $this->createMock(StatsService::class);
        $stats->method('getMonthlyStats')->willReturn($bundle);

        $sheet = new RewardSheet($stats, $service, $config);
        $file = $sheet->generate(123, '2026-02', 100.0, $bundle, 'test');
        $this->assertFileExists($file);

        $html = file_get_contents($file);
        $this->assertStringContainsString('Top Helper', $html);
        $this->assertStringContainsString('Most Balanced', $html);
        $this->assertStringContainsString('Consistency King', $html);
        $this->assertStringContainsString('Fast Responder', $html);
    }
}
