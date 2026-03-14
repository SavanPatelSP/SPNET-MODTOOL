<?php

namespace App\Reports;

use App\Services\StatsService;
use App\Services\RewardService;

class MultiChatReport
{
    private StatsService $stats;
    private RewardService $rewards;
    private array $config;

    public function __construct(StatsService $stats, RewardService $rewards, array $config)
    {
        $this->stats = $stats;
        $this->rewards = $rewards;
        $this->config = $config;
    }

    public function generate(array $chatIds, ?string $month, ?float $budget): string
    {
        $bundle = $this->stats->getMonthlyStatsForChats($chatIds, $month);
        $mods = $bundle['mods'];
        $ranked = $budget !== null ? $this->rewards->rankAndReward($mods, $budget) : $mods;

        $rewardMap = [];
        foreach ($ranked as $item) {
            if (isset($item['reward'])) {
                $rewardMap[$item['user_id']] = $item['reward'];
            }
        }

        $modsSorted = $mods;
        usort($modsSorted, fn($a, $b) => $b['score'] <=> $a['score']);

        $brand = $this->config['report']['brand_name'] ?? 'Mod Rewards';
        $accent = $this->config['report']['accent_color'] ?? '#ff7a59';
        $secondary = $this->config['report']['secondary_color'] ?? '#1f2a44';

        $html = $this->renderHtml([
            'brand' => $brand,
            'accent' => $accent,
            'secondary' => $secondary,
            'label' => $bundle['range']['label'],
            'budget' => $budget,
            'summary' => $bundle['summary'],
            'mods' => $modsSorted,
            'reward_map' => $rewardMap,
            'chats' => $bundle['chats'],
            'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ]);

        $safeMonth = $bundle['range']['month'];
        $file = __DIR__ . '/../../storage/reports/multi-chat-summary-' . $safeMonth . '.html';
        file_put_contents($file, $html);
        return realpath($file) ?: $file;
    }

    private function renderHtml(array $data): string
    {
        $rows = '';
        $rank = 1;
        foreach (array_slice($data['mods'], 0, 15) as $mod) {
            $reward = $data['reward_map'][$mod['user_id']] ?? 0.0;
            $rows .= '<tr>';
            $rows .= '<td>' . $rank . '</td>';
            $rows .= '<td>' . $this->escape($mod['display_name']) . '</td>';
            $rows .= '<td>' . number_format($mod['score'], 2) . '</td>';
            $rows .= '<td>' . (int)$mod['messages'] . '</td>';
            $rows .= '<td>' . (int)$mod['warnings'] . '</td>';
            $rows .= '<td>' . (int)$mod['mutes'] . '</td>';
            $rows .= '<td>' . (int)$mod['bans'] . '</td>';
            $rows .= '<td>' . number_format($mod['active_minutes'] / 60, 1) . 'h</td>';
            $rows .= '<td>' . number_format($reward, 2) . '</td>';
            $rows .= '</tr>';
            $rank++;
        }

        $chatCards = '';
        foreach ($data['chats'] as $chat) {
            $summary = $chat['summary'] ?? [];
            $topMod = $chat['top_mod'] ?? null;
            $chatCards .= '<div class="card">';
            $chatCards .= '<h3>' . $this->escape($chat['title']) . '</h3>';
            $chatCards .= '<div class="meta">Chat ID ' . $this->escape((string)$chat['chat_id']) . '</div>';
            $chatCards .= '<div class="stats">';
            $chatCards .= '<div><span>Messages</span><strong>' . (int)($summary['messages'] ?? 0) . '</strong></div>';
            $chatCards .= '<div><span>Actions</span><strong>' . (int)(($summary['warnings'] ?? 0) + ($summary['mutes'] ?? 0) + ($summary['bans'] ?? 0)) . '</strong></div>';
            $chatCards .= '<div><span>Active Hours</span><strong>' . number_format(($summary['active_minutes'] ?? 0) / 60, 1) . 'h</strong></div>';
            $chatCards .= '<div><span>Budget</span><strong>' . number_format((float)($chat['budget'] ?? 0), 2) . '</strong></div>';
            $chatCards .= '</div>';
            if ($topMod) {
                $chatCards .= '<div class="top">Top Mod: ' . $this->escape($topMod['display_name']) . '</div>';
            }
            $chatCards .= '</div>';
        }

        $summary = $data['summary'];

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>' . $this->escape($data['brand']) . ' - Multi Chat Summary</title>
<style>
:root { --accent: ' . $data['accent'] . '; --secondary: ' . $data['secondary'] . '; }
* { box-sizing: border-box; }
body {
    font-family: "Avenir Next", "Avenir", "Trebuchet MS", Verdana, sans-serif;
    margin: 0;
    background: radial-gradient(circle at top left, #fef6ed 0%, #eef3ff 40%, #f8f9fb 100%);
    color: var(--secondary);
}
.container {
    max-width: 1200px;
    margin: 36px auto;
    padding: 0 20px 40px;
}
.header {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    background: #ffffff;
    border-radius: 18px;
    padding: 20px 26px;
    box-shadow: 0 20px 40px rgba(31, 42, 68, 0.12);
}
.header h1 { margin: 0; font-size: 26px; }
.meta { font-size: 13px; color: #6b7280; }
.summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin: 20px 0;
}
.summary .card {
    background: #ffffff;
    border-radius: 14px;
    padding: 16px;
    box-shadow: 0 12px 30px rgba(31, 42, 68, 0.08);
    border: 1px solid #eff2f5;
}
.summary h3 {
    margin: 0 0 8px;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #9a3412;
}
.summary .value { font-size: 22px; font-weight: 700; }
.section-title { font-size: 18px; margin: 26px 0 12px; }
.table {
    width: 100%;
    border-collapse: collapse;
    background: #ffffff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(31, 42, 68, 0.08);
}
.table th, .table td {
    padding: 10px 12px;
    border-bottom: 1px solid #eef0f4;
    text-align: left;
    font-size: 13px;
}
.table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; }
.chat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; }
.card {
    background: #ffffff;
    border-radius: 16px;
    padding: 16px;
    border: 1px solid #eff2f5;
    box-shadow: 0 14px 30px rgba(31, 42, 68, 0.08);
}
.card h3 { margin: 0; font-size: 15px; }
.card .meta { margin-top: 4px; }
.card .stats { margin-top: 12px; display: grid; gap: 8px; }
.card .stats div { display: flex; justify-content: space-between; font-size: 13px; }
.card .stats span { color: #6b7280; }
.card .top { margin-top: 10px; font-size: 13px; font-weight: 600; color: var(--accent); }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>' . $this->escape($data['brand']) . ' Multi-Chat Summary</h1>
            <div class="meta">' . $this->escape($data['label']) . '</div>
        </div>
        <div class="meta">Generated ' . $this->escape($data['generated_at']) . '</div>
    </div>

    <div class="summary">
        <div class="card"><h3>Mods Tracked</h3><div class="value">' . (int)($summary['total_mods'] ?? 0) . '</div></div>
        <div class="card"><h3>Total Messages</h3><div class="value">' . (int)($summary['messages'] ?? 0) . '</div></div>
        <div class="card"><h3>Actions</h3><div class="value">' . (int)(($summary['warnings'] ?? 0) + ($summary['mutes'] ?? 0) + ($summary['bans'] ?? 0)) . '</div></div>
        <div class="card"><h3>Active Hours</h3><div class="value">' . number_format(($summary['active_minutes'] ?? 0) / 60, 1) . 'h</div></div>
    </div>

    <div class="section-title">Overall Leaderboard</div>
    <table class="table">
        <thead>
        <tr>
            <th>#</th>
            <th>Mod</th>
            <th>Score</th>
            <th>Msgs</th>
            <th>Warn</th>
            <th>Mute</th>
            <th>Ban</th>
            <th>Active</th>
            <th>Reward</th>
        </tr>
        </thead>
        <tbody>' . $rows . '</tbody>
    </table>

    <div class="section-title">Per Chat Breakdown</div>
    <div class="chat-grid">' . $chatCards . '</div>
</div>
</body>
</html>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
