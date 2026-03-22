<?php

namespace App\Reports;

use App\Services\StatsService;
use App\Services\RewardService;
use App\Services\RewardContextService;
use App\Services\RewardHistoryService;
use App\Services\ArchiveService;

class RewardCsv
{
    private StatsService $stats;
    private RewardService $rewards;
    private ?RewardContextService $contextService;
    private ?RewardHistoryService $history;
    private ?ArchiveService $archive;

    public function __construct(StatsService $stats, RewardService $rewards, ?RewardContextService $contextService = null, ?RewardHistoryService $history = null, ?ArchiveService $archive = null)
    {
        $this->stats = $stats;
        $this->rewards = $rewards;
        $this->contextService = $contextService;
        $this->history = $history;
        $this->archive = $archive;
    }

    public function generate(int|string $chatId, ?string $month, float $budget): string
    {
        $bundle = $this->stats->getMonthlyStats($chatId, $month);
        $mods = $bundle['mods'];
        $context = $this->contextService ? $this->contextService->build($chatId, $bundle['range']['month']) : [];
        $context['chat_id'] = (int)$chatId;
        $context['month'] = $bundle['range']['month'] ?? $month;
        $context['source'] = 'reward_csv';
        $ranked = $this->rewards->rankAndReward($mods, $budget, $context);

        $rewardMap = [];
        $bonusMap = [];
        $eligibleMap = [];
        foreach ($ranked as $item) {
            $rewardMap[$item['user_id']] = $item['reward'];
            $bonusMap[$item['user_id']] = $item['bonus'] ?? 0.0;
            if (array_key_exists('eligible', $item)) {
                $eligibleMap[$item['user_id']] = (bool)$item['eligible'];
            }
        }

        usort($mods, fn($a, $b) => $b['score'] <=> $a['score']);

        $file = __DIR__ . '/../../storage/reports/reward-sheet-' . $chatId . '-' . $bundle['range']['month'] . '.csv';
        $fp = fopen($file, 'w');

        fputcsv($fp, [
            'Rank', 'Mod', 'Role', 'Eligible', 'Score', 'Impact Score', 'Consistency %',
            'Messages', 'Messages (Bot)', 'Messages (External)',
            'Warnings', 'Mutes', 'Bans', 'Active Hours', 'Active Hours (External)',
            'Membership Hours', 'Days Active', 'Improvement %', 'Trend 3M %', 'Bonus', 'Reward'
        ]);

        $rank = 1;
        foreach ($mods as $mod) {
            $reward = $rewardMap[$mod['user_id']] ?? 0.0;
            $bonus = $bonusMap[$mod['user_id']] ?? 0.0;
            $eligible = $eligibleMap[$mod['user_id']] ?? null;
            fputcsv($fp, [
                $rank,
                $mod['display_name'],
                $mod['role'] ?? '',
                $eligible === null ? '' : ($eligible ? 'Yes' : 'No'),
                number_format($mod['score'], 2, '.', ''),
                number_format($mod['impact_score'] ?? 0, 2, '.', ''),
                number_format($mod['consistency_index'] ?? 0, 1, '.', ''),
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
                $mod['trend_3m'] !== null ? number_format($mod['trend_3m'], 1, '.', '') : '',
                number_format($bonus, 2, '.', ''),
                number_format($reward, 2, '.', ''),
            ]);
            $rank++;
        }

        fclose($fp);
        $path = realpath($file) ?: $file;

        if ($this->history) {
            $this->history->record($chatId, $bundle['range']['month'], $ranked);
        }
        if ($this->archive) {
            $this->archive->record((int)$chatId, 'reward_csv', $bundle['range']['month'], $path);
        }

        return $path;
    }
}
