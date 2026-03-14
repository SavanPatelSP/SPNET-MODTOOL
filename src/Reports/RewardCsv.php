<?php

namespace App\Reports;

use App\Services\StatsService;
use App\Services\RewardService;

class RewardCsv
{
    private StatsService $stats;
    private RewardService $rewards;

    public function __construct(StatsService $stats, RewardService $rewards)
    {
        $this->stats = $stats;
        $this->rewards = $rewards;
    }

    public function generate(int|string $chatId, ?string $month, float $budget): string
    {
        $bundle = $this->stats->getMonthlyStats($chatId, $month);
        $mods = $bundle['mods'];
        $ranked = $this->rewards->rankAndReward($mods, $budget);

        $rewardMap = [];
        $eligibleMap = [];
        foreach ($ranked as $item) {
            $rewardMap[$item['user_id']] = $item['reward'];
            if (array_key_exists('eligible', $item)) {
                $eligibleMap[$item['user_id']] = (bool)$item['eligible'];
            }
        }

        usort($mods, fn($a, $b) => $b['score'] <=> $a['score']);

        $file = __DIR__ . '/../../storage/reports/reward-sheet-' . $chatId . '-' . $bundle['range']['month'] . '.csv';
        $fp = fopen($file, 'w');

        fputcsv($fp, [
            'Rank', 'Mod', 'Eligible', 'Score', 'Messages', 'Messages (Bot)', 'Messages (External)',
            'Warnings', 'Mutes', 'Bans', 'Active Hours', 'Active Hours (External)',
            'Membership Hours', 'Days Active', 'Improvement %', 'Reward'
        ]);

        $rank = 1;
        foreach ($mods as $mod) {
            $reward = $rewardMap[$mod['user_id']] ?? 0.0;
            $eligible = $eligibleMap[$mod['user_id']] ?? null;
            fputcsv($fp, [
                $rank,
                $mod['display_name'],
                $eligible === null ? '' : ($eligible ? 'Yes' : 'No'),
                number_format($mod['score'], 2, '.', ''),
                $mod['messages'],
                $mod['internal_messages'] ?? $mod['messages'],
                $mod['external_messages'] ?? 0,
                $mod['warnings'],
                $mod['mutes'],
                $mod['bans'],
                number_format($mod['active_minutes'] / 60, 1, '.', ''),
                number_format(($mod['external_active_minutes'] ?? 0) / 60, 1, '.', ''),
                number_format($mod['membership_minutes'] / 60, 1, '.', ''),
                $mod['days_active'],
                $mod['improvement'] !== null ? number_format($mod['improvement'], 1, '.', '') : '',
                number_format($reward, 2, '.', ''),
            ]);
            $rank++;
        }

        fclose($fp);
        return realpath($file) ?: $file;
    }
}
