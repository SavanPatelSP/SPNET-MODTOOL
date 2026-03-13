<?php

namespace App\Reports;

use App\Services\StatsService;
use App\Services\RewardService;

class RewardSheet
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

    public function generate(int|string $chatId, ?string $month, float $budget): string
    {
        $bundle = $this->stats->getMonthlyStats($chatId, $month);
        $mods = $bundle['mods'];
        $ranked = $this->rewards->rankAndReward($mods, $budget);

        $rewardMap = [];
        foreach ($ranked as $item) {
            $rewardMap[$item['user_id']] = $item['reward'];
        }

        $modsSorted = $mods;
        usort($modsSorted, fn($a, $b) => $b['score'] <=> $a['score']);

        $topScore = $modsSorted[0]['score'] ?? 1;

        $insights = $this->buildInsights($modsSorted);

        $brand = $this->config['report']['brand_name'] ?? 'Mod Rewards';
        $accent = $this->config['report']['accent_color'] ?? '#ff7a59';
        $secondary = $this->config['report']['secondary_color'] ?? '#1f2a44';

        $summary = $bundle['summary'];
        $label = $bundle['range']['label'];

        $html = $this->renderHtml([
            'brand' => $brand,
            'accent' => $accent,
            'secondary' => $secondary,
            'label' => $label,
            'budget' => $budget,
            'summary' => $summary,
            'mods' => $modsSorted,
            'reward_map' => $rewardMap,
            'top_score' => $topScore,
            'insights' => $insights,
            'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ]);

        $safeMonth = $bundle['range']['month'];
        $file = __DIR__ . '/../../storage/reports/reward-sheet-' . $chatId . '-' . $safeMonth . '.html';
        file_put_contents($file, $html);
        return realpath($file) ?: $file;
    }

    private function buildInsights(array $mods): array
    {
        if (empty($mods)) {
            return [];
        }

        $mostActive = $this->maxBy($mods, 'active_minutes');
        $mostConsistent = $this->maxBy($mods, 'days_active');
        $mostImproved = $this->maxBy($mods, 'improvement');
        $mostModeration = $this->maxBy($mods, 'actions_total');

        $insights = [];

        $top = $mods[0];
        $insights[] = 'Top Mod: ' . $top['display_name'] . ' with score ' . number_format($top['score'], 2);

        if ($mostActive) {
            $insights[] = 'Most Active: ' . $mostActive['display_name'] . ' with ' . number_format($mostActive['active_minutes'], 1) . ' active minutes.';
        }

        if ($mostConsistent) {
            $insights[] = 'Most Consistent: ' . $mostConsistent['display_name'] . ' with ' . $mostConsistent['days_active'] . ' active days.';
        }

        if ($mostImproved && $mostImproved['improvement'] !== null) {
            $insights[] = 'Most Improved: ' . $mostImproved['display_name'] . ' up ' . number_format($mostImproved['improvement'], 1) . '%.';
        }

        if ($mostModeration) {
            $insights[] = 'Most Moderation Actions: ' . $mostModeration['display_name'] . ' with ' . $mostModeration['actions_total'] . ' actions.';
        }

        return $insights;
    }

    private function maxBy(array $mods, string $field): ?array
    {
        $best = null;
        foreach ($mods as $mod) {
            if (!isset($mod[$field])) {
                continue;
            }
            if ($best === null || $mod[$field] > $best[$field]) {
                $best = $mod;
            }
        }
        return $best;
    }

    private function renderHtml(array $data): string
    {
        $mods = $data['mods'];
        foreach ($mods as &$mod) {
            $mod['actions_total'] = $mod['warnings'] + $mod['mutes'] + $mod['bans'];
        }
        unset($mod);

        $rows = '';
        $rank = 1;
        foreach ($mods as $mod) {
            $reward = $data['reward_map'][$mod['user_id']] ?? 0.0;
            $scorePercent = $data['top_score'] > 0 ? ($mod['score'] / $data['top_score']) * 100 : 0;
            $rows .= '<tr>';
            $rows .= '<td>' . $rank . '</td>';
            $rows .= '<td>' . $this->escape($mod['display_name']) . '</td>';
            $rows .= '<td>' . number_format($mod['score'], 2) . '<div class="bar"><span style="width:' . number_format($scorePercent, 2) . '%"></span></div></td>';
            $rows .= '<td>' . $mod['messages'] . '</td>';
            $rows .= '<td>' . $mod['warnings'] . '</td>';
            $rows .= '<td>' . $mod['mutes'] . '</td>';
            $rows .= '<td>' . $mod['bans'] . '</td>';
            $rows .= '<td>' . number_format($mod['active_minutes'] / 60, 1) . 'h</td>';
            $rows .= '<td>' . number_format($mod['membership_minutes'] / 60, 1) . 'h</td>';
            $rows .= '<td>' . $mod['days_active'] . '</td>';
            $rows .= '<td>' . ($mod['improvement'] !== null ? number_format($mod['improvement'], 1) . '%' : 'N/A') . '</td>';
            $rows .= '<td>' . number_format($reward, 2) . '</td>';
            $rows .= '</tr>';
            $rank++;
        }

        $insightHtml = '';
        foreach ($data['insights'] as $insight) {
            $insightHtml .= '<li>' . $this->escape($insight) . '</li>';
        }

        $summary = $data['summary'];

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>' . $this->escape($data['brand']) . ' - ' . $this->escape($data['label']) . '</title>
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
    max-width: 1100px;
    margin: 40px auto;
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 24px 60px rgba(31, 42, 68, 0.12);
    padding: 32px 40px 40px;
}
.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid #eef0f4;
    padding-bottom: 18px;
    margin-bottom: 24px;
}
.header h1 {
    font-size: 28px;
    margin: 0;
}
.header .meta {
    text-align: right;
    font-size: 13px;
    color: #6b7280;
}
.summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.card {
    background: #f7f4ef;
    border: 1px solid #efe7dd;
    border-radius: 14px;
    padding: 16px;
}
.card h3 {
    margin: 0 0 8px;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #a16207;
}
.card .value {
    font-size: 22px;
    font-weight: 700;
}
.section-title {
    font-size: 18px;
    margin: 24px 0 12px;
}
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.table th, .table td {
    text-align: left;
    padding: 10px 8px;
    border-bottom: 1px solid #eef0f4;
}
.table th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6b7280;
}
.bar {
    margin-top: 6px;
    height: 6px;
    background: #eef0f4;
    border-radius: 999px;
    overflow: hidden;
}
.bar span {
    display: block;
    height: 100%;
    background: var(--accent);
}
.insights {
    margin-top: 18px;
    padding: 16px;
    background: #1f2a44;
    color: #f9fafb;
    border-radius: 14px;
}
.insights ul {
    margin: 0;
    padding-left: 18px;
}
.footer {
    margin-top: 24px;
    font-size: 12px;
    color: #6b7280;
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>' . $this->escape($data['brand']) . '</h1>
            <div>' . $this->escape($data['label']) . ' Reward Sheet</div>
        </div>
        <div class="meta">
            <div>Budget: ' . number_format($data['budget'], 2) . '</div>
            <div>Generated: ' . $this->escape($data['generated_at']) . '</div>
        </div>
    </div>

    <div class="summary">
        <div class="card">
            <h3>Mods Tracked</h3>
            <div class="value">' . (int)$summary['total_mods'] . '</div>
        </div>
        <div class="card">
            <h3>Total Messages</h3>
            <div class="value">' . (int)$summary['messages'] . '</div>
        </div>
        <div class="card">
            <h3>Active Hours</h3>
            <div class="value">' . number_format($summary['active_minutes'] / 60, 1) . 'h</div>
        </div>
        <div class="card">
            <h3>Total Actions</h3>
            <div class="value">' . (int)($summary['warnings'] + $summary['mutes'] + $summary['bans']) . '</div>
        </div>
        <div class="card">
            <h3>Avg Score</h3>
            <div class="value">' . number_format($summary['avg_score'], 2) . '</div>
        </div>
    </div>

    <div class="section-title">Leaderboard</div>
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
            <th>Member</th>
            <th>Days</th>
            <th>Trend</th>
            <th>Reward</th>
        </tr>
        </thead>
        <tbody>
        ' . $rows . '
        </tbody>
    </table>

    <div class="section-title">Highlights</div>
    <div class="insights">
        <ul>
            ' . $insightHtml . '
        </ul>
    </div>

    <div class="footer">
        Tip: Active time is based on message gaps, membership time is based on join/leave events.
    </div>
</div>
</body>
</html>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
