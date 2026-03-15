<?php

namespace App\Reports;

use App\Services\StatsService;
use App\Services\RewardService;
use App\Services\RewardContextService;
use App\Services\RewardHistoryService;
use App\Services\ArchiveService;
use App\Services\ReportApprovalService;

class RewardSheet
{
    private StatsService $stats;
    private RewardService $rewards;
    private array $config;
    private ?RewardContextService $contextService;
    private ?RewardHistoryService $history;
    private ?ArchiveService $archive;
    private ?ReportApprovalService $approvals;

    public function __construct(StatsService $stats, RewardService $rewards, array $config, ?RewardContextService $contextService = null, ?RewardHistoryService $history = null, ?ArchiveService $archive = null, ?ReportApprovalService $approvals = null)
    {
        $this->stats = $stats;
        $this->rewards = $rewards;
        $this->config = $config;
        $this->contextService = $contextService;
        $this->history = $history;
        $this->archive = $archive;
        $this->approvals = $approvals;
    }

    public function generate(int|string $chatId, ?string $month, float $budget, ?array $bundle = null, ?string $suffix = null): string
    {
        $bundle = $bundle ?? $this->stats->getMonthlyStats($chatId, $month);
        $mods = $bundle['mods'];
        $context = $this->contextService ? $this->contextService->build($chatId, $bundle['range']['month']) : [];
        $ranked = $this->rewards->rankAndReward($mods, $budget, $context);

        $rewardMap = [];
        $bonusMap = [];
        $eligibilityMap = [];
        $rewardScoreMap = [];
        foreach ($ranked as $item) {
            $rewardMap[$item['user_id']] = $item['reward'];
            $bonusMap[$item['user_id']] = $item['bonus'] ?? 0.0;
            if (array_key_exists('reward_score', $item)) {
                $rewardScoreMap[$item['user_id']] = $item['reward_score'];
            }
            if (array_key_exists('eligible', $item)) {
                $eligibilityMap[$item['user_id']] = (bool)$item['eligible'];
            }
        }

        $modsSorted = $mods;
        foreach ($modsSorted as &$mod) {
            if (isset($rewardScoreMap[$mod['user_id']])) {
                $mod['reward_score'] = $rewardScoreMap[$mod['user_id']];
            }
        }
        unset($mod);

        $rankBy = $this->config['reward']['rank_by'] ?? 'score';
        $rankKey = ($rankBy === 'reward_score' && !empty($rewardScoreMap)) ? 'reward_score' : 'score';
        usort($modsSorted, fn($a, $b) => ($b[$rankKey] ?? 0) <=> ($a[$rankKey] ?? 0));

        $topScore = $modsSorted[0][$rankKey] ?? 1;

        $insights = $this->buildInsights($modsSorted);

        $brand = $this->config['report']['brand_name'] ?? 'Mod Rewards';
        $accent = $this->config['report']['accent_color'] ?? '#ff7a59';
        $secondary = $this->config['report']['secondary_color'] ?? '#1f2a44';

        $summary = $bundle['summary'];
        $label = $bundle['range']['label'];

        $approvalRequired = !empty($bundle['settings']['approval_required']);
        $approvalStatus = null;
        if ($approvalRequired && $this->approvals) {
            $approvalStatus = $this->approvals->getStatus($chatId, $bundle['range']['month'], 'reward');
        }

        $html = $this->renderHtml([
            'brand' => $brand,
            'accent' => $accent,
            'secondary' => $secondary,
            'label' => $label,
            'budget' => $budget,
            'rank_key' => $rankKey,
            'approval_required' => $approvalRequired,
            'approval_status' => $approvalStatus,
            'summary' => $summary,
            'mods' => $modsSorted,
            'reward_map' => $rewardMap,
            'bonus_map' => $bonusMap,
            'eligibility_map' => $eligibilityMap,
            'top_score' => $topScore,
            'insights' => $insights,
            'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ]);

        $safeMonth = $bundle['range']['month'];
        $fileSuffix = $suffix ?? $safeMonth;
        $file = __DIR__ . '/../../storage/reports/reward-sheet-' . $chatId . '-' . $fileSuffix . '.html';
        file_put_contents($file, $html);
        $path = realpath($file) ?: $file;

        if ($this->history) {
            $this->history->record($chatId, $bundle['range']['month'], $ranked);
        }
        if ($this->archive) {
            $this->archive->record((int)$chatId, 'reward_sheet', $bundle['range']['month'], $path);
        }

        return $path;
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
        $insights[] = [
            'title' => 'Top Mod',
            'value' => $top['display_name'],
            'meta' => 'Score ' . number_format($top['score'], 2),
        ];

        if ($mostActive) {
            $insights[] = [
                'title' => 'Most Active',
                'value' => $mostActive['display_name'],
                'meta' => number_format($mostActive['active_minutes'] / 60, 1) . ' hours',
            ];
        }

        if ($mostConsistent) {
            $insights[] = [
                'title' => 'Most Consistent',
                'value' => $mostConsistent['display_name'],
                'meta' => $mostConsistent['days_active'] . ' active days',
            ];
        }

        if ($mostImproved && $mostImproved['improvement'] !== null) {
            $insights[] = [
                'title' => 'Most Improved',
                'value' => $mostImproved['display_name'],
                'meta' => 'Up ' . number_format($mostImproved['improvement'], 1) . '%',
            ];
        }

        if ($mostModeration) {
            $insights[] = [
                'title' => 'Most Actions',
                'value' => $mostModeration['display_name'],
                'meta' => $mostModeration['actions_total'] . ' actions',
            ];
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
        $rankKey = $data['rank_key'] ?? 'score';
        foreach ($mods as &$mod) {
            $mod['actions_total'] = $mod['warnings'] + $mod['mutes'] + $mod['bans'];
        }
        unset($mod);

        $rows = '';
        $rank = 1;
        foreach ($mods as $mod) {
            $reward = $data['reward_map'][$mod['user_id']] ?? 0.0;
            $metricValue = $mod[$rankKey] ?? ($mod['score'] ?? 0);
            $scorePercent = $data['top_score'] > 0 ? ($metricValue / $data['top_score']) * 100 : 0;
            $eligible = $data['eligibility_map'][$mod['user_id']] ?? true;
            $rowClass = $eligible ? '' : 'row-ineligible';
            $internalMessages = $mod['internal_messages'] ?? $mod['messages'];
            $externalMessages = $mod['external_messages'] ?? 0;
            $internalActive = $mod['internal_active_minutes'] ?? $mod['active_minutes'];
            $externalActive = $mod['external_active_minutes'] ?? 0;

            $trendLabel = 'N/A';
            $trendClass = 'trend-flat';
            if ($mod['improvement'] !== null) {
                if ($mod['improvement'] >= 0) {
                    $trendLabel = 'Up &uarr; ' . number_format($mod['improvement'], 1) . '%';
                    $trendClass = 'trend-up';
                } else {
                    $trendLabel = 'Down &darr; ' . number_format(abs($mod['improvement']), 1) . '%';
                    $trendClass = 'trend-down';
                }
            }
            $trend3mLabel = 'N/A';
            if (($mod['trend_3m'] ?? null) !== null) {
                $trend3mLabel = ($mod['trend_3m'] >= 0 ? '+' : '') . number_format($mod['trend_3m'], 1) . '%';
            }
            $impact = $mod['impact_score'] ?? 0;
            $consistency = $mod['consistency_index'] ?? 0;
            $roleLabel = $mod['role'] ?? '-';
            $bonus = $data['bonus_map'][$mod['user_id']] ?? 0.0;

            $rows .= '<tr class="' . $rowClass . '">';
            $rows .= '<td>' . $rank . '</td>';
            $rows .= '<td>' . $this->escape($mod['display_name']) . '</td>';
            $rows .= '<td>' . $this->escape($roleLabel) . '</td>';
            $rows .= '<td>' . number_format($mod['score'], 2) . '<div class="bar"><span style="width:' . number_format($scorePercent, 2) . '%"></span></div></td>';
            $rows .= '<td>' . number_format($impact, 2) . '</td>';
            $rows .= '<td>' . number_format($consistency, 1) . '%</td>';
            $rows .= '<td>' . $mod['messages'] . '<div class="sub">Bot ' . (int)$internalMessages . ' | External ' . (int)$externalMessages . '</div></td>';
            $rows .= '<td>' . $mod['warnings'] . '</td>';
            $rows .= '<td>' . $mod['mutes'] . '</td>';
            $rows .= '<td>' . $mod['bans'] . '</td>';
            $rows .= '<td>' . number_format($mod['active_minutes'] / 60, 1) . 'h<div class="sub">Bot ' . number_format($internalActive / 60, 1) . 'h | External ' . number_format($externalActive / 60, 1) . 'h</div></td>';
            $rows .= '<td>' . number_format($mod['membership_minutes'] / 60, 1) . 'h</td>';
            $rows .= '<td>' . $mod['days_active'] . '</td>';
            $rows .= '<td><span class="trend ' . $trendClass . '">' . $trendLabel . '</span><div class="sub">3m ' . $trend3mLabel . '</div></td>';
            $rows .= '<td>' . ($eligible ? 'Eligible' : 'Below minimums') . '</td>';
            $rows .= '<td>' . number_format($bonus, 2) . '</td>';
            $rows .= '<td>' . number_format($reward, 2) . '</td>';
            $rows .= '</tr>';
            $rank++;
        }

        $insightHtml = '';
        foreach ($data['insights'] as $insight) {
            $insightHtml .= '<div class="badge">';
            $insightHtml .= '<div class="badge-title">' . $this->escape($insight['title']) . '</div>';
            $insightHtml .= '<div class="badge-value">' . $this->escape($insight['value']) . '</div>';
            if (!empty($insight['meta'])) {
                $insightHtml .= '<div class="badge-meta">' . $this->escape($insight['meta']) . '</div>';
            }
            $insightHtml .= '</div>';
        }

        $summary = $data['summary'];
        $approvalRequired = !empty($data['approval_required']);
        $approvalStatus = $data['approval_status']['status'] ?? 'pending';
        $approvalLine = $approvalRequired ? ('Approval: ' . strtoupper($approvalStatus)) : '';
        $draftStamp = ($approvalRequired && $approvalStatus !== 'approved') ? '<div class="stamp">DRAFT</div>' : '';

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
.stamp {
    position: absolute;
    top: 18px;
    right: 22px;
    background: #ffe4e6;
    color: #9f1239;
    font-weight: 700;
    letter-spacing: 2px;
    padding: 6px 12px;
    border-radius: 10px;
    border: 2px solid #fda4af;
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
    background: #ffffff;
    border: 1px solid #eef0f4;
    border-radius: 14px;
    padding: 16px;
    box-shadow: 0 10px 24px rgba(31, 42, 68, 0.08);
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
.sub {
    margin-top: 6px;
    font-size: 11px;
    color: #6b7280;
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
.row-ineligible {
    opacity: 0.65;
}
.trend {
    font-weight: 600;
}
.trend-up {
    color: #16a34a;
}
.trend-down {
    color: #dc2626;
}
.trend-flat {
    color: #6b7280;
}
.badge-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}
.badge {
    background: #1f2a44;
    color: #f9fafb;
    border-radius: 14px;
    padding: 14px 16px;
    box-shadow: 0 14px 28px rgba(31, 42, 68, 0.18);
}
.badge-title {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #93c5fd;
}
.badge-value {
    font-size: 16px;
    font-weight: 700;
    margin-top: 6px;
}
.badge-meta {
    font-size: 12px;
    color: #cbd5f5;
    margin-top: 4px;
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
    ' . $draftStamp . '
    <div class="header">
        <div>
            <h1>' . $this->escape($data['brand']) . '</h1>
            <div>' . $this->escape($data['label']) . ' Reward Sheet</div>
        </div>
        <div class="meta">
            <div>Budget: ' . number_format($data['budget'], 2) . '</div>
            ' . ($approvalLine !== '' ? '<div>' . $approvalLine . '</div>' : '') . '
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
            <div class="sub">Bot ' . (int)($summary['internal_messages'] ?? 0) . ' | External ' . (int)($summary['external_messages'] ?? 0) . '</div>
        </div>
        <div class="card">
            <h3>Active Hours</h3>
            <div class="value">' . number_format($summary['active_minutes'] / 60, 1) . 'h</div>
            <div class="sub">Bot ' . number_format(($summary['internal_active_minutes'] ?? 0) / 60, 1) . 'h | External ' . number_format(($summary['external_active_minutes'] ?? 0) / 60, 1) . 'h</div>
        </div>
        <div class="card">
            <h3>Total Actions</h3>
            <div class="value">' . (int)($summary['warnings'] + $summary['mutes'] + $summary['bans']) . '</div>
        </div>
        <div class="card">
            <h3>Avg Score</h3>
            <div class="value">' . number_format($summary['avg_score'], 2) . '</div>
        </div>
        <div class="card">
            <h3>Avg Impact</h3>
            <div class="value">' . number_format($summary['avg_impact'], 2) . '</div>
        </div>
        <div class="card">
            <h3>Avg Consistency</h3>
            <div class="value">' . number_format($summary['avg_consistency'], 1) . '%</div>
        </div>
    </div>

    <div class="section-title">Leaderboard</div>
    <table class="table">
        <thead>
        <tr>
            <th>#</th>
            <th>Mod</th>
            <th>Role</th>
            <th>Score</th>
            <th>Impact</th>
            <th>Consist</th>
            <th>Msgs</th>
            <th>Warn</th>
            <th>Mute</th>
            <th>Ban</th>
            <th>Active</th>
            <th>Member</th>
            <th>Days</th>
            <th>Trend</th>
            <th>Eligible</th>
            <th>Bonus</th>
            <th>Reward</th>
        </tr>
        </thead>
        <tbody>
        ' . $rows . '
        </tbody>
    </table>

    <div class="section-title">Highlights</div>
    <div class="badge-grid">
        ' . $insightHtml . '
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
