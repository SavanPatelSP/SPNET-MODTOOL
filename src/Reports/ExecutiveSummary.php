<?php

namespace App\Reports;

use App\Services\StatsService;
use App\Services\RewardService;
use App\Services\RewardContextService;
use App\Services\ArchiveService;

class ExecutiveSummary
{
    private StatsService $stats;
    private RewardService $rewards;
    private array $config;
    private ?RewardContextService $contextService;
    private ?ArchiveService $archive;

    public function __construct(StatsService $stats, RewardService $rewards, array $config, ?RewardContextService $contextService = null, ?ArchiveService $archive = null)
    {
        $this->stats = $stats;
        $this->rewards = $rewards;
        $this->config = $config;
        $this->contextService = $contextService;
        $this->archive = $archive;
    }

    public function generate(int|string $chatId, ?string $month, float $budget): string
    {
        $bundle = $this->stats->getMonthlyStats($chatId, $month);
        $context = $this->contextService ? $this->contextService->build($chatId, $bundle['range']['month']) : [];
        $context['chat_id'] = (int)$chatId;
        $context['month'] = $bundle['range']['month'] ?? $month;
        $context['source'] = 'executive_summary';
        $ranked = $this->rewards->rankAndReward($bundle['mods'], $budget, $context);

        $summary = $bundle['summary'];
        $topMods = array_slice($ranked, 0, 3);

        $brand = $this->config['report']['brand_name'] ?? 'Mod Rewards';
        $accent = $this->config['report']['accent_color'] ?? '#ff7a59';
        $secondary = $this->config['report']['secondary_color'] ?? '#1f2a44';

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8" />
        <title>' . $this->escape($brand) . ' Executive Summary</title>
        <style>
        :root { --accent: ' . $accent . '; --secondary: ' . $secondary . '; }
        body { font-family: "Avenir Next", "Avenir", "Trebuchet MS", Verdana, sans-serif; background: #f8fafc; color: var(--secondary); margin: 0; }
        .container { max-width: 900px; margin: 32px auto; padding: 0 20px 40px; }
        .header { background: #fff; padding: 18px 20px; border-radius: 14px; box-shadow: 0 16px 30px rgba(15,23,42,0.08); }
        .title { font-size: 22px; margin: 0 0 6px; }
        .meta { font-size: 12px; color: #64748b; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 16px 0; }
        .card { background: #fff; padding: 14px; border-radius: 12px; box-shadow: 0 10px 24px rgba(15,23,42,0.07); }
        .card h4 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: #9a3412; }
        .value { font-size: 20px; font-weight: 700; }
        .section { margin-top: 18px; }
        .section h3 { margin: 0 0 10px; font-size: 13px; letter-spacing: 0.08em; text-transform: uppercase; color: #64748b; }
        .list { background: #fff; padding: 12px; border-radius: 12px; }
        </style></head><body><div class="container">
        <div class="header">
            <div class="title">' . $this->escape($brand) . ' Executive Summary</div>
            <div class="meta">' . $this->escape($bundle['range']['label']) . ' · Budget ' . number_format($budget, 2) . '</div>
        </div>
        <div class="grid">
            <div class="card"><h4>Mods Tracked</h4><div class="value">' . (int)($summary['total_mods'] ?? 0) . '</div></div>
            <div class="card"><h4>Messages</h4><div class="value">' . (int)($summary['messages'] ?? 0) . '</div></div>
            <div class="card"><h4>Actions</h4><div class="value">' . (int)(($summary['warnings'] ?? 0) + ($summary['mutes'] ?? 0) + ($summary['bans'] ?? 0)) . '</div></div>
            <div class="card"><h4>Active Hours</h4><div class="value">' . number_format(($summary['active_minutes'] ?? 0) / 60, 1) . 'h</div></div>
        </div>
        <div class="section"><h3>Top Mods</h3><div class="list">';

        foreach ($topMods as $mod) {
            $html .= '<div>' . $this->escape($mod['display_name']) . ' — Score ' . number_format($mod['score'], 2) . ' · Reward ' . number_format($mod['reward'], 2) . '</div>';
        }

        $html .= '</div></div></div></body></html>';

        $file = __DIR__ . '/../../storage/reports/executive-summary-' . $chatId . '-' . $bundle['range']['month'] . '.html';
        file_put_contents($file, $html);
        $path = realpath($file) ?: $file;

        if ($this->archive) {
            $this->archive->record((int)$chatId, 'executive_summary', $bundle['range']['month'], $path);
        }

        return $path;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
