<?php

namespace Tests;

use App\Services\RewardService;
use PHPUnit\Framework\TestCase;

class RewardServiceTest extends TestCase
{
    public function testRankAndRewardAllocatesBudgetAndEligibility(): void
    {
        $config = require __DIR__ . '/../config.example.php';
        $config['reward']['top_n'] = 3;
        $config['reward']['rank_by'] = 'score';
        $config['eligibility']['min_messages'] = 1;

        $service = new RewardService($config);

        $mods = [
            [
                'user_id' => 1,
                'display_name' => 'Alpha',
                'messages' => 120,
                'warnings' => 2,
                'mutes' => 0,
                'bans' => 0,
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
                'active_minutes' => 300,
                'membership_minutes' => 600,
                'days_active' => 8,
                'score' => 28,
                'impact_score' => 7,
                'consistency_index' => 60,
                'role_multiplier' => 1.0,
                'improvement' => 2,
            ],
            [
                'user_id' => 3,
                'display_name' => 'Charlie',
                'messages' => 0,
                'warnings' => 0,
                'mutes' => 0,
                'bans' => 0,
                'active_minutes' => 0,
                'membership_minutes' => 200,
                'days_active' => 0,
                'score' => 0,
                'impact_score' => 0,
                'consistency_index' => 0,
                'role_multiplier' => 1.0,
                'improvement' => null,
            ],
        ];

        $ranked = $service->rankAndReward($mods, 100.0);

        $total = 0.0;
        foreach ($ranked as $mod) {
            $total += (float)($mod['reward'] ?? 0);
        }

        $this->assertEqualsWithDelta(100.0, $total, 0.01);

        $ineligible = array_values(array_filter($ranked, static fn(array $mod): bool => (int)$mod['user_id'] === 3));
        $this->assertCount(1, $ineligible);
        $this->assertFalse((bool)($ineligible[0]['eligible'] ?? true));
        $this->assertEquals(0.0, (float)($ineligible[0]['reward'] ?? 0.0));
    }
}
