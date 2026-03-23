<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Services\SettingsService;
use App\Services\StatsService;
use App\Services\RewardService;
use App\Services\RewardContextService;
use App\Services\RetentionRiskService;
use App\Services\AuditLogService;
use App\Services\PdfService;

$dashboardConfig = $config['dashboard'] ?? [];
$token = $dashboardConfig['token'] ?? null;
$provided = $_GET['token'] ?? ($_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? null);

if ($token && $provided !== $token) {
    http_response_code(403);
    echo 'Forbidden: invalid dashboard token.';
    exit;
}

if (!$token) {
    http_response_code(403);
    echo 'Dashboard token not set. Configure dashboard.token in config.local.php.';
    exit;
}

$db = new Database($config['db']);
$settingsService = new SettingsService($db, $config);
$statsService = new StatsService($db, $settingsService, $config);
$rewardService = new RewardService($config);
$rewardService->setAuditLogger(new AuditLogService($db));
$rewardContext = new RewardContextService($db, $config);
$retentionRisk = new RetentionRiskService($statsService, $settingsService, $config);

$chatId = $_GET['chat_id'] ?? ($dashboardConfig['default_chat_id'] ?? null);
$month = $_GET['month'] ?? null;
$budgetOverride = isset($_GET['budget']) ? (float)$_GET['budget'] : null;
$renderPdf = (($_GET['format'] ?? '') === 'pdf');

$chats = $db->fetchAll('SELECT id, title, type FROM chats ORDER BY title ASC');
if (!$chatId && !empty($chats)) {
    $chatId = $chats[0]['id'];
}

if (!$chatId) {
    echo 'No chats found yet. Add the bot to a group and send a message.';
    exit;
}

$bundle = $statsService->getMonthlyStats($chatId, $month);
if (empty($bundle['mods'])) {
    echo 'No mods configured yet for this chat.';
    exit;
}

$range = $bundle['range'] ?? [];
$timezone = $bundle['timezone'] ?? ($config['timezone'] ?? 'UTC');
$tz = new DateTimeZone($timezone);
$chatRow = $db->fetch('SELECT title FROM chats WHERE id = ? LIMIT 1', [$chatId]);
$chatTitle = $chatRow['title'] ?? ('Chat ' . $chatId);

$budget = $budgetOverride ?? (float)($bundle['settings']['reward_budget'] ?? 0);
$context = $rewardContext->build($chatId, $range['month'] ?? null);
$context['chat_id'] = (int)$chatId;
$context['month'] = $range['month'] ?? $month;
$context['source'] = 'manager_digest';
$ranked = $rewardService->rankAndReward($bundle['mods'], $budget, $context);
$pdfUrl = 'manager-digest.php?' . http_build_query(array_merge(
    ['token' => $token, 'chat_id' => $chatId, 'month' => $range['month'] ?? $month, 'format' => 'pdf'],
    $budgetOverride !== null ? ['budget' => $budgetOverride] : []
));

usort($ranked, fn($a, $b) => ($b['reward'] ?? 0) <=> ($a['reward'] ?? 0));
$topMods = array_slice($ranked, 0, 5);

$summary = $bundle['summary'] ?? [];
$totalActions = (int)($summary['warnings'] ?? 0) + (int)($summary['mutes'] ?? 0) + (int)($summary['bans'] ?? 0);
$rewardTotal = 0.0;
foreach ($ranked as $mod) {
    $rewardTotal += (float)($mod['reward'] ?? 0);
}

$eligibility = $config['eligibility'] ?? [];
$minDaysRule = (int)($eligibility['min_days_active'] ?? 0);
$minMessagesRule = (int)($eligibility['min_messages'] ?? 0);
$minScoreRule = (float)($eligibility['min_score'] ?? 0);
$minActionsRule = (int)($eligibility['min_actions'] ?? 0);
$minActiveHoursRule = (float)($eligibility['min_active_hours'] ?? 0);
$eligibilityAlerts = [];
foreach ($bundle['mods'] as $mod) {
    $reasons = [];
    $actions = (int)(($mod['warnings'] ?? 0) + ($mod['mutes'] ?? 0) + ($mod['bans'] ?? 0));
    $activeHours = ((float)($mod['active_minutes'] ?? 0)) / 60;
    if ($minDaysRule > 0 && ($mod['days_active'] ?? 0) < $minDaysRule) {
        $reasons[] = 'days';
    }
    if ($minMessagesRule > 0 && ($mod['messages'] ?? 0) < $minMessagesRule) {
        $reasons[] = 'msgs';
    }
    if ($minScoreRule > 0 && ($mod['score'] ?? 0) < $minScoreRule) {
        $reasons[] = 'score';
    }
    if ($minActionsRule > 0 && $actions < $minActionsRule) {
        $reasons[] = 'actions';
    }
    if ($minActiveHoursRule > 0 && $activeHours < $minActiveHoursRule) {
        $reasons[] = 'hours';
    }
    if (!empty($reasons)) {
        $eligibilityAlerts[] = $mod['display_name'] . ' (' . implode(', ', $reasons) . ')';
    }
}

$retentionReport = $retentionRisk->buildReport($chatId, $range['month'] ?? null, null);
$retentionRisks = array_slice($retentionReport['risks'] ?? [], 0, 5);

$recentStats = $statsService->getRollingStats($chatId, 7);
$inactiveMods = [];
foreach ($recentStats['mods'] ?? [] as $mod) {
    if ((int)($mod['messages'] ?? 0) === 0 && (float)($mod['active_minutes'] ?? 0) <= 0) {
        $inactiveMods[] = $mod['display_name'];
    }
}
$inactiveMods = array_slice($inactiveMods, 0, 6);

$actionQualityConfig = $config['action_quality'] ?? [];
$qualityThreshold = (float)($actionQualityConfig['actions_per_1k'] ?? 30);
$qualityMinMessages = (int)($actionQualityConfig['min_messages'] ?? 50);
$qualityMinActions = (int)($actionQualityConfig['min_actions'] ?? 3);
$actionQualityAlerts = [];
foreach ($bundle['mods'] as $mod) {
    $messages = (int)($mod['messages'] ?? 0);
    $actions = (int)($mod['warnings'] ?? 0) + (int)($mod['mutes'] ?? 0) + (int)($mod['bans'] ?? 0);
    if ($messages < $qualityMinMessages || $actions < $qualityMinActions) {
        continue;
    }
    $per1k = $messages > 0 ? ($actions / $messages) * 1000 : 0;
    if ($per1k >= $qualityThreshold) {
        $actionQualityAlerts[] = [
            'name' => $mod['display_name'],
            'per1k' => $per1k,
            'actions' => $actions,
            'messages' => $messages,
        ];
    }
}
usort($actionQualityAlerts, fn($a, $b) => $b['per1k'] <=> $a['per1k']);
$actionQualityAlerts = array_slice($actionQualityAlerts, 0, 6);

$coverageConfig = $config['coverage'] ?? [];
$coverageMinMods = (int)($coverageConfig['min_mods_per_hour'] ?? 1);
$coverageMap = $statsService->getCoverageHeatmap($chatId, $range, $timezone, $coverageMinMods);
$coverageScore = (float)($coverageMap['coverage_pct'] ?? 0);
$coverageGaps = array_slice($coverageMap['gaps'] ?? [], 0, 8);
$coverageMax = (int)($coverageMap['max_mods'] ?? 0);

$retentionContributors = buildRetentionContributors($bundle['mods']);
$workloadBalanceScore = computeWorkloadBalanceScore($bundle['mods']);
$avgConsistency = (float)($summary['avg_consistency'] ?? 0);
$teamHealthScore = round(($avgConsistency * 0.4) + ($coverageScore * 0.35) + ($workloadBalanceScore * 0.25), 1);
$teamHealthLabel = 'At Risk';
if ($teamHealthScore >= 80) {
    $teamHealthLabel = 'Great';
} elseif ($teamHealthScore >= 60) {
    $teamHealthLabel = 'Stable';
} elseif ($teamHealthScore >= 40) {
    $teamHealthLabel = 'Needs Attention';
}

$performanceBadges = buildPerformanceBadges($bundle['mods'], $config);
$bonusPlannerSummary = $rewardService->buildBonusPlannerSummary($bundle['mods'], $budget);
$fairnessConfig = $config['reward_fairness'] ?? [];
$targetMinReward = (float)($fairnessConfig['min_reward'] ?? ($config['reward']['min_reward'] ?? 0));
$budgetOptimizer = $rewardService->estimateMinimumBudget($bundle['mods'], $budget, $targetMinReward, $context);

$nowLocal = new DateTimeImmutable('now', $tz);
$currentMonthKey = $nowLocal->format('Y-m');
$targetMonthKey = $range['month'] ?? $month ?? $currentMonthKey;
$rewardForecast = null;
$rewardForecastNote = null;
if ($targetMonthKey === $currentMonthKey) {
    $mtdBundle = $statsService->getMonthToDateStats($chatId, $nowLocal);
    $lastMonthKey = $nowLocal->modify('first day of last month')->format('Y-m');
    $lastBundle = $statsService->getMonthlyStats($chatId, $lastMonthKey);
    $rewardForecast = buildRewardForecast($mtdBundle, $lastBundle, $budget, $config);
} else {
    $rewardForecastNote = 'Forecast is available for the current month only.';
}

function percentDrop(?float $current, ?float $previous): ?float
{
    if ($previous === null || $previous <= 0) {
        return null;
    }
    $drop = (($previous - $current) / $previous) * 100;
    if ($drop <= 0) {
        return 0.0;
    }
    return round($drop, 1);
}

function computeWorkloadBalanceScore(array $mods): float
{
    $total = 0.0;
    foreach ($mods as $mod) {
        $total += (float)($mod['active_minutes'] ?? 0);
    }
    if ($total <= 0) {
        return 0.0;
    }
    $sorted = $mods;
    usort($sorted, fn($a, $b) => ($b['active_minutes'] ?? 0) <=> ($a['active_minutes'] ?? 0));
    $topTotal = 0.0;
    foreach (array_slice($sorted, 0, 3) as $mod) {
        $topTotal += (float)($mod['active_minutes'] ?? 0);
    }
    $share = $topTotal / $total;
    if ($share <= 0.45) {
        return 100.0;
    }
    if ($share >= 0.80) {
        return 20.0;
    }
    $slope = 80 / (0.80 - 0.45);
    $score = 100 - (($share - 0.45) * $slope);
    return max(20.0, min(100.0, round($score, 1)));
}

function buildRetentionContributors(array $mods): array
{
    if (empty($mods)) {
        return [];
    }
    $maxImpact = 0.0;
    $maxActive = 0.0;
    foreach ($mods as $mod) {
        $impact = (float)($mod['impact_score'] ?? 0);
        $active = (float)($mod['active_minutes'] ?? 0);
        if ($impact > $maxImpact) {
            $maxImpact = $impact;
        }
        if ($active > $maxActive) {
            $maxActive = $active;
        }
    }
    $maxImpact = max(1.0, $maxImpact);
    $maxActive = max(1.0, $maxActive);

    $contributors = [];
    foreach ($mods as $mod) {
        $consistency = (float)($mod['consistency_index'] ?? 0);
        $impact = (float)($mod['impact_score'] ?? 0);
        $active = (float)($mod['active_minutes'] ?? 0);
        $score = ($consistency / 100) * 0.4 + ($impact / $maxImpact) * 0.3 + ($active / $maxActive) * 0.3;
        $contributors[] = [
            'name' => $mod['display_name'],
            'score' => $score,
            'consistency' => $consistency,
            'impact' => $impact,
            'active_hours' => $active / 60,
        ];
    }
    usort($contributors, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($contributors, 0, 5);
}

function buildPerformanceBadges(array $mods, array $config): array
{
    if (empty($mods)) {
        return [];
    }

    $weights = $config['score_weights'] ?? [];
    $fastMinMessages = 15;
    $fastMinHours = 1.0;
    $balancedMinMessages = 15;
    $balancedMinActions = 1;

    $topHelper = null;
    $topHelperMessages = -1;
    $consistencyKing = null;
    $consistencyMax = -1.0;
    $fastResponder = null;
    $fastRate = null;
    $fastFallback = null;
    $fastFallbackRate = null;
    $mostBalanced = null;
    $mostBalancedScore = null;
    $balancedFallback = null;
    $balancedFallbackScore = null;
    $mostBalancedMeta = '';
    $balancedFallbackMeta = '';

    foreach ($mods as $mod) {
        $messages = (int)($mod['messages'] ?? 0);
        if ($messages > $topHelperMessages) {
            $topHelperMessages = $messages;
            $topHelper = $mod;
        }
        $consistency = (float)($mod['consistency_index'] ?? 0);
        if ($consistency > $consistencyMax) {
            $consistencyMax = $consistency;
            $consistencyKing = $mod;
        }

        $activeMinutes = (float)($mod['active_minutes'] ?? 0);
        $hours = $activeMinutes / 60;
        if ($messages > 0 && $hours > 0) {
            $rate = $messages / $hours;
            if ($messages >= $fastMinMessages && $hours >= $fastMinHours) {
                if ($fastRate === null || $rate > $fastRate) {
                    $fastRate = $rate;
                    $fastResponder = $mod;
                }
            } else {
                if ($fastFallbackRate === null || $rate > $fastFallbackRate) {
                    $fastFallbackRate = $rate;
                    $fastFallback = $mod;
                }
            }
        }

        $warnings = (int)($mod['warnings'] ?? 0);
        $mutes = (int)($mod['mutes'] ?? 0);
        $bans = (int)($mod['bans'] ?? 0);
        $actions = $warnings + $mutes + $bans;
        $daysActive = (int)($mod['days_active'] ?? 0);
        $roleMultiplier = (float)($mod['role_multiplier'] ?? 1.0);

        $chatScore = 0.0;
        $chatScore += log(1 + $messages) * ($weights['message'] ?? 1.0);
        $chatScore += sqrt($activeMinutes) * ($weights['active_minute'] ?? 0.0);
        $chatScore += $daysActive * ($weights['day_active'] ?? 0.0);
        $chatScore *= $roleMultiplier;

        $actionScore = 0.0;
        $actionScore += $warnings * ($weights['warn'] ?? 1.0);
        $actionScore += $mutes * ($weights['mute'] ?? 1.0);
        $actionScore += $bans * ($weights['ban'] ?? 1.0);
        $actionScore *= $roleMultiplier;

        if ($chatScore > 0 && $actionScore > 0) {
            $balanceRatio = 1 - (abs($chatScore - $actionScore) / max($chatScore, $actionScore, 1.0));
            $score = ($chatScore + $actionScore) * $balanceRatio;
            $meta = 'Balance ' . number_format($balanceRatio * 100, 0) . '% · ' . $messages . ' msgs · ' . $actions . ' actions';

            if ($messages >= $balancedMinMessages && $actions >= $balancedMinActions) {
                if ($mostBalancedScore === null || $score > $mostBalancedScore) {
                    $mostBalancedScore = $score;
                    $mostBalanced = $mod;
                    $mostBalancedMeta = $meta;
                }
            } else {
                if ($balancedFallbackScore === null || $score > $balancedFallbackScore) {
                    $balancedFallbackScore = $score;
                    $balancedFallback = $mod;
                    $balancedFallbackMeta = $meta;
                }
            }
        }
    }

    if ($fastResponder === null && $fastFallback !== null) {
        $fastResponder = $fastFallback;
        $fastRate = $fastFallbackRate;
    }
    if ($mostBalanced === null && $balancedFallback !== null) {
        $mostBalanced = $balancedFallback;
        $mostBalancedMeta = $balancedFallbackMeta;
    }

    $badges = [];
    if ($topHelper) {
        $badges[] = [
            'title' => 'Top Helper',
            'name' => $topHelper['display_name'],
            'meta' => number_format((int)($topHelper['messages'] ?? 0)) . ' msgs',
        ];
    }
    if ($mostBalanced) {
        $badges[] = [
            'title' => 'Most Balanced',
            'name' => $mostBalanced['display_name'],
            'meta' => $mostBalancedMeta,
        ];
    }
    if ($consistencyKing) {
        $badges[] = [
            'title' => 'Consistency King',
            'name' => $consistencyKing['display_name'],
            'meta' => number_format((float)($consistencyKing['consistency_index'] ?? 0), 1) . '%',
        ];
    }
    if ($fastResponder && $fastRate !== null) {
        $badges[] = [
            'title' => 'Fast Responder',
            'name' => $fastResponder['display_name'],
            'meta' => number_format($fastRate, 1) . ' msgs/hr',
        ];
    }

    return $badges;
}

function buildActivityIndex(array $summary, array $weights): float
{
    $messages = (float)($summary['messages'] ?? 0);
    $actions = (float)($summary['warnings'] ?? 0) + (float)($summary['mutes'] ?? 0) + (float)($summary['bans'] ?? 0);
    $activeHours = ((float)($summary['active_minutes'] ?? 0)) / 60;

    $defaults = [
        'messages' => 1.0,
        'actions' => 3.0,
        'active_hours' => 0.5,
    ];
    $weights = array_merge($defaults, is_array($weights) ? $weights : []);

    return ($messages * (float)$weights['messages'])
        + ($actions * (float)$weights['actions'])
        + ($activeHours * (float)$weights['active_hours']);
}

function buildRewardForecast(array $mtdBundle, array $lastBundle, float $budget, array $config): array
{
    $forecastWeights = $config['forecast_weights'] ?? [];
    $mtdSummary = $mtdBundle['summary'] ?? [];
    $lastSummary = $lastBundle['summary'] ?? [];

    $currentIndex = buildActivityIndex($mtdSummary, $forecastWeights);
    $baselineIndex = buildActivityIndex($lastSummary, $forecastWeights);

    $range = $mtdBundle['range'] ?? [];
    $startLocal = $range['start_local'] ?? null;
    $endLocal = $range['end_local'] ?? null;
    if (!$startLocal instanceof DateTimeImmutable || !$endLocal instanceof DateTimeImmutable) {
        return [
            'status' => 'invalid_range',
        ];
    }

    $daysElapsed = max(1, (int)$startLocal->diff($endLocal)->days + 1);
    $daysTotal = max(1, (int)$startLocal->diff($startLocal->modify('first day of next month'))->days);
    $paceFactor = $daysTotal / $daysElapsed;

    $projectedMessages = (float)($mtdSummary['messages'] ?? 0) * $paceFactor;
    $projectedActions = ((float)($mtdSummary['warnings'] ?? 0) + (float)($mtdSummary['mutes'] ?? 0) + (float)($mtdSummary['bans'] ?? 0)) * $paceFactor;
    $projectedActiveHours = (((float)($mtdSummary['active_minutes'] ?? 0)) / 60) * $paceFactor;
    $projectedIndex = $currentIndex * $paceFactor;

    $baselineIndex = $baselineIndex > 0 ? $baselineIndex : ($currentIndex > 0 ? $currentIndex : 1.0);
    $forecastBudget = $budget > 0 ? $budget * ($projectedIndex / $baselineIndex) : 0.0;
    $deltaPct = $budget > 0 ? (($forecastBudget - $budget) / $budget) * 100 : 0.0;

    return [
        'status' => 'ok',
        'days_elapsed' => $daysElapsed,
        'days_total' => $daysTotal,
        'pace_factor' => $paceFactor,
        'projected_messages' => $projectedMessages,
        'projected_actions' => $projectedActions,
        'projected_active_hours' => $projectedActiveHours,
        'projected_index' => $projectedIndex,
        'baseline_index' => $baselineIndex,
        'forecast_budget' => $forecastBudget,
        'delta_pct' => $deltaPct,
    ];
}

$spikeDefaults = $config['inactivity_spike_defaults'] ?? [];
$spikeConfig = $config['inactivity_spike'] ?? [];
$spikeThreshold = (float)($spikeDefaults['threshold'] ?? 35);
$windowDays = (int)($spikeConfig['window_days'] ?? 7);
$minPrevMessages = (int)($spikeConfig['min_prev_messages'] ?? 200);
$minPrevActiveHours = (float)($spikeConfig['min_prev_active_hours'] ?? 20);
$minPrevActiveMods = (int)($spikeConfig['min_prev_active_mods'] ?? 3);

$nowLocal = new DateTimeImmutable('now', $tz);
$currentStats = $statsService->getRollingStats($chatId, $windowDays, $nowLocal);
$prevStats = $statsService->getRollingStats($chatId, $windowDays, $nowLocal->modify('-' . $windowDays . ' days'));

$spikeReasons = [];
if (!empty($currentStats['mods']) && !empty($prevStats['mods'])) {
    $currSummary = $currentStats['summary'] ?? [];
    $prevSummary = $prevStats['summary'] ?? [];

    $currMessages = (float)($currSummary['messages'] ?? 0);
    $prevMessages = (float)($prevSummary['messages'] ?? 0);
    $currActiveHours = ((float)($currSummary['active_minutes'] ?? 0)) / 60;
    $prevActiveHours = ((float)($prevSummary['active_minutes'] ?? 0)) / 60;

    $currActiveMods = 0;
    foreach ($currentStats['mods'] as $mod) {
        if ((int)($mod['messages'] ?? 0) > 0 || (float)($mod['active_minutes'] ?? 0) > 0) {
            $currActiveMods++;
        }
    }
    $prevActiveMods = 0;
    foreach ($prevStats['mods'] as $mod) {
        if ((int)($mod['messages'] ?? 0) > 0 || (float)($mod['active_minutes'] ?? 0) > 0) {
            $prevActiveMods++;
        }
    }

    $dropMessages = percentDrop($currMessages, $prevMessages);
    if ($prevMessages >= $minPrevMessages && $dropMessages !== null && $dropMessages >= $spikeThreshold) {
        $spikeReasons[] = 'Messages down ' . number_format($dropMessages, 1) . '% (' . number_format($prevMessages) . '→' . number_format($currMessages) . ')';
    }

    $dropActive = percentDrop($currActiveHours, $prevActiveHours);
    if ($prevActiveHours >= $minPrevActiveHours && $dropActive !== null && $dropActive >= $spikeThreshold) {
        $spikeReasons[] = 'Active hours down ' . number_format($dropActive, 1) . '% (' . number_format($prevActiveHours, 1) . 'h→' . number_format($currActiveHours, 1) . 'h)';
    }

    $dropActiveMods = percentDrop((float)$currActiveMods, (float)$prevActiveMods);
    if ($prevActiveMods >= $minPrevActiveMods && $dropActiveMods !== null && $dropActiveMods >= $spikeThreshold) {
        $spikeReasons[] = 'Active mods down ' . number_format($dropActiveMods, 1) . '% (' . number_format($prevActiveMods) . '→' . number_format($currActiveMods) . ')';
    }
}

$monthOptions = [];
$cursor = new DateTimeImmutable('first day of this month', $tz);
for ($i = 0; $i < 8; $i++) {
    $monthOptions[] = $cursor->format('Y-m');
    $cursor = $cursor->modify('-1 month');
}

$dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

$accent = $config['report']['accent_color'] ?? '#ff7a59';
$secondary = $config['report']['secondary_color'] ?? '#1f2a44';
$brand = $config['report']['brand_name'] ?? 'SP NET MOD TOOL';

if ($renderPdf) {
    ob_start();
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'); ?> Manager Digest</title>
<style>
:root {
    --accent: <?php echo $accent; ?>;
    --secondary: <?php echo $secondary; ?>;
    --muted: #64748b;
    --border: #e2e8f0;
    --surface: #ffffff;
    --shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
}
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: "Avenir Next", "Avenir", "Trebuchet MS", Verdana, sans-serif;
    background: radial-gradient(circle at top right, #fef6ed 0%, #e6f2ff 45%, #f8fafc 100%);
    color: var(--secondary);
}
.container {
    max-width: 1180px;
    margin: 28px auto 60px;
    padding: 0 20px;
}
.hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 18px;
    align-items: center;
    background: var(--surface);
    border-radius: 18px;
    padding: 20px 24px;
    box-shadow: var(--shadow);
    border: 1px solid #eef2f7;
}
.kicker {
    font-size: 11px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--muted);
}
.hero h1 {
    margin: 6px 0 8px;
    font-size: 24px;
}
.meta {
    font-size: 12px;
    color: var(--muted);
}
.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}
.chip {
    background: #f1f5f9;
    border: 1px solid var(--border);
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 600;
}
.panel {
    background: var(--surface);
    border-radius: 16px;
    padding: 16px 20px;
    margin-top: 18px;
    box-shadow: var(--shadow);
    border: 1px solid #eef2f7;
}
.control-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
}
label {
    display: grid;
    gap: 6px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted);
}
select, input {
    padding: 9px 10px;
    border-radius: 10px;
    border: 1px solid var(--border);
    font-size: 13px;
    color: var(--secondary);
    background: #f8fafc;
}
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid var(--border);
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
    color: var(--secondary);
    background: #fff;
}
.button.primary {
    background: var(--accent);
    color: #fff;
    border: none;
}
.summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-top: 18px;
}
.card {
    background: var(--surface);
    border-radius: 14px;
    padding: 16px;
    box-shadow: var(--shadow);
    border: 1px solid #eff2f5;
}
.card h3 {
    margin: 0 0 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #9a3412;
}
.card .value {
    font-size: 22px;
    font-weight: 700;
}
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 14px;
    margin-top: 16px;
}
.section-title {
    margin: 0 0 10px;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted);
}
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
th, td {
    text-align: left;
    padding: 8px 6px;
    border-bottom: 1px solid #eef2f7;
}
th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted);
}
.pill {
    display: inline-flex;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: #f8fafc;
    border: 1px solid var(--border);
}
.pill.high { background: #fee2e2; border-color: #fecaca; color: #b91c1c; }
.pill.medium { background: #ffedd5; border-color: #fed7aa; color: #c2410c; }
.pill.low { background: #e0f2fe; border-color: #bae6fd; color: #0369a1; }
.muted {
    color: var(--muted);
    font-size: 12px;
}
.list {
    display: grid;
    gap: 6px;
    font-size: 13px;
}
.heatmap {
    display: grid;
    gap: 8px;
}
.heat-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.heat-label {
    width: 32px;
    font-size: 11px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.heat-cells {
    flex: 1;
    display: grid;
    grid-template-columns: repeat(24, minmax(10px, 1fr));
    gap: 2px;
}
.heat-cell {
    height: 14px;
    border-radius: 3px;
    background: #f1f5f9;
    border: 1px solid #eef2f7;
}
.heat-legend {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: var(--muted);
    margin-top: 8px;
}
@media (max-width: 900px) {
    .hero {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="container">
    <div class="hero">
        <div>
            <div class="kicker"><?php echo htmlspecialchars($range['label'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
            <h1><?php echo htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'); ?> Manager Digest</h1>
            <div class="meta"><?php echo htmlspecialchars((string)$chatTitle, ENT_QUOTES, 'UTF-8'); ?> | Chat ID <?php echo htmlspecialchars((string)$chatId, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="meta">Generated <?php echo htmlspecialchars((new DateTimeImmutable('now', $tz))->format('Y-m-d H:i'), ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($tz->getName(), ENT_QUOTES, 'UTF-8'); ?>)</div>
            <div class="chips">
                <span class="chip">Mods <?php echo (int)($summary['total_mods'] ?? 0); ?></span>
                <span class="chip">Msgs <?php echo (int)($summary['messages'] ?? 0); ?></span>
                <span class="chip">Actions <?php echo $totalActions; ?></span>
                <span class="chip">Budget <?php echo number_format($budget, 2); ?></span>
                <span class="chip">Rewards <?php echo number_format($rewardTotal, 2); ?></span>
            </div>
        </div>
        <div>
            <form method="get">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars((string)$token, ENT_QUOTES, 'UTF-8'); ?>" />
                <div class="control-grid">
                    <label>
                        Chat
                        <select name="chat_id">
                            <?php foreach ($chats as $chat): ?>
                                <option value="<?php echo htmlspecialchars((string)$chat['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ((string)$chat['id'] === (string)$chatId) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(($chat['title'] ?: $chat['id']), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Month
                        <input list="month-list" type="text" name="month" value="<?php echo htmlspecialchars((string)($range['month'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                        <datalist id="month-list">
                            <?php foreach ($monthOptions as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </label>
                    <label>
                        Budget
                        <input type="number" step="0.01" name="budget" value="<?php echo htmlspecialchars((string)$budget, ENT_QUOTES, 'UTF-8'); ?>" />
                    </label>
                </div>
                <div style="margin-top:10px;">
                    <button class="button primary" type="submit">Update</button>
                    <a class="button" href="<?php echo htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8'); ?>">Download PDF</a>
                </div>
            </form>
        </div>
    </div>

    <div class="summary">
        <div class="card">
            <h3>Total Messages</h3>
            <div class="value"><?php echo number_format((int)($summary['messages'] ?? 0)); ?></div>
            <div class="muted">Chat activity for the month.</div>
        </div>
        <div class="card">
            <h3>Active Hours</h3>
            <div class="value"><?php echo number_format(((float)($summary['active_minutes'] ?? 0)) / 60, 1); ?>h</div>
            <div class="muted">Session‑based time.</div>
        </div>
        <div class="card">
            <h3>Moderation Actions</h3>
            <div class="value"><?php echo number_format($totalActions); ?></div>
            <div class="muted">Warnings, mutes, bans.</div>
        </div>
        <div class="card">
            <h3>Reward Pool</h3>
            <div class="value"><?php echo number_format($budget, 2); ?></div>
            <div class="muted">Recommended payout <?php echo number_format($rewardTotal, 2); ?></div>
        </div>
        <div class="card">
            <h3>Team Health</h3>
            <div class="value"><?php echo number_format($teamHealthScore, 1); ?></div>
            <div class="muted"><?php echo htmlspecialchars($teamHealthLabel, ENT_QUOTES, 'UTF-8'); ?> · Balance <?php echo number_format($workloadBalanceScore, 1); ?>%</div>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="section-title">Top 5 Mods</div>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Mod</th>
                        <th>Score</th>
                        <th>Reward</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topMods as $i => $mod): ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><?php echo htmlspecialchars($mod['display_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format((float)($mod['score'] ?? 0), 2); ?></td>
                        <td><?php echo number_format((float)($mod['reward'] ?? 0), 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <div class="section-title">Rewards Outlook</div>
            <div class="list">
                <div>Eligible mods: <?php echo number_format(count($ranked)); ?></div>
                <div>Average reward: <?php echo number_format(count($ranked) > 0 ? $rewardTotal / count($ranked) : 0, 2); ?></div>
                <div>Top reward: <?php echo number_format((float)($topMods[0]['reward'] ?? 0), 2); ?></div>
                <div class="muted">Adjust budget to tune reward distribution.</div>
            </div>
        </div>

        <div class="card">
            <div class="section-title">Performance Badges</div>
            <?php if (empty($performanceBadges)): ?>
                <div class="muted">No badges available yet.</div>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($performanceBadges as $badge): ?>
                        <div>
                            <?php echo htmlspecialchars($badge['title'], ENT_QUOTES, 'UTF-8'); ?>:
                            <?php echo htmlspecialchars($badge['name'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if (!empty($badge['meta'])): ?>
                                <span class="muted">(<?php echo htmlspecialchars($badge['meta'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="section-title">Reward Forecast (MTD)</div>
            <?php if (empty($rewardForecast) || ($rewardForecast['status'] ?? '') !== 'ok'): ?>
                <div class="muted"><?php echo htmlspecialchars($rewardForecastNote ?? 'Forecast unavailable.', ENT_QUOTES, 'UTF-8'); ?></div>
            <?php else: ?>
                <div class="value"><?php echo number_format((float)$rewardForecast['forecast_budget'], 2); ?></div>
                <div class="muted">Estimated pool at current pace · Day <?php echo (int)$rewardForecast['days_elapsed']; ?>/<?php echo (int)$rewardForecast['days_total']; ?></div>
                <div class="list">
                    <div>Projected msgs <?php echo number_format((float)$rewardForecast['projected_messages']); ?></div>
                    <div>Projected actions <?php echo number_format((float)$rewardForecast['projected_actions']); ?></div>
                    <div>Projected active <?php echo number_format((float)$rewardForecast['projected_active_hours'], 1); ?>h</div>
                    <div class="muted"><?php echo (($rewardForecast['delta_pct'] ?? 0) >= 0 ? '+' : '') . number_format((float)($rewardForecast['delta_pct'] ?? 0), 1); ?>% vs current budget</div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="section-title">Budget Optimizer</div>
            <?php if (($budgetOptimizer['status'] ?? '') === 'disabled'): ?>
                <div class="muted">Set reward_fairness.min_reward to enable.</div>
            <?php elseif (($budgetOptimizer['status'] ?? '') === 'no_eligible'): ?>
                <div class="muted">No eligible mods yet.</div>
            <?php elseif (($budgetOptimizer['status'] ?? '') === 'unreachable'): ?>
                <div class="list">
                    <div>Min target <?php echo number_format($targetMinReward, 2); ?> per mod</div>
                    <div class="muted">Even <?php echo number_format((float)($budgetOptimizer['min_budget'] ?? 0), 2); ?> only yields <?php echo number_format((float)($budgetOptimizer['min_reward'] ?? 0), 2); ?></div>
                </div>
            <?php else: ?>
                <?php
                    $optBudget = (float)($budgetOptimizer['min_budget'] ?? 0);
                    $deltaPct = $budget > 0 ? (($optBudget - $budget) / $budget) * 100 : 0;
                ?>
                <div class="value"><?php echo number_format($optBudget, 2); ?></div>
                <div class="muted">Min budget for <?php echo number_format($targetMinReward, 2); ?> per eligible mod</div>
                <div class="list">
                    <div>Eligible mods <?php echo (int)($budgetOptimizer['eligible'] ?? 0); ?></div>
                    <div class="muted"><?php echo ($deltaPct >= 0 ? '+' : '') . number_format($deltaPct, 1); ?>% vs current budget</div>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="section-title">Bonus Split Planner</div>
            <?php if (empty($bonusPlannerSummary) || (float)($bonusPlannerSummary['pool_amount'] ?? 0) <= 0): ?>
                <div class="muted">No bonus planner pool configured.</div>
            <?php else: ?>
                <div class="list">
                    <div>Pool <?php echo number_format((float)($bonusPlannerSummary['pool_amount'] ?? 0), 2); ?> (<?php echo number_format(((float)($bonusPlannerSummary['pool_percent'] ?? 0)) * 100, 1); ?>%)</div>
                    <?php foreach (($bonusPlannerSummary['badges'] ?? []) as $badge): ?>
                        <div>
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($badge['key'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>:
                            <?php echo number_format((float)($badge['amount'] ?? 0), 2); ?>
                            <?php if (!empty($badge['winner'])): ?>
                                <span class="muted">(<?php echo htmlspecialchars((string)$badge['winner'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach (($bonusPlannerSummary['roles'] ?? []) as $role): ?>
                        <div>
                            Role <?php echo htmlspecialchars((string)($role['role'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>:
                            <?php echo number_format((float)($role['amount'] ?? 0), 2); ?>
                            <span class="muted">(<?php echo (int)($role['count'] ?? 0); ?> mods · <?php echo number_format((float)($role['per_mod'] ?? 0), 2); ?>/mod)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="section-title">Retention Risks</div>
            <?php if (empty($retentionRisks)): ?>
                <div class="muted">No retention risks detected.</div>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($retentionRisks as $risk): ?>
                        <div>
                            <span class="pill <?php echo strtolower($risk['severity'] ?? 'low'); ?>"><?php echo htmlspecialchars($risk['severity'] ?? 'LOW', ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php echo htmlspecialchars($risk['display_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            <span class="muted">(-<?php echo number_format((float)($risk['max_drop'] ?? 0), 1); ?>%)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="section-title">Eligibility Alerts</div>
            <?php if (empty($eligibilityAlerts)): ?>
                <div class="muted">All mods meet reward thresholds.</div>
            <?php else: ?>
                <div class="list">
                    <?php foreach (array_slice($eligibilityAlerts, 0, 6) as $alert): ?>
                        <div><?php echo htmlspecialchars($alert, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="section-title">Recent Inactivity (7 days)</div>
            <?php if (empty($inactiveMods)): ?>
                <div class="muted">No inactive mods detected.</div>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($inactiveMods as $modName): ?>
                        <div><?php echo htmlspecialchars($modName, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="section-title">Inactivity Spike Watch</div>
            <?php if (empty($spikeReasons)): ?>
                <div class="muted">No spike detected for last <?php echo $windowDays; ?> days.</div>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($spikeReasons as $reason): ?>
                        <div><?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="muted" style="margin-top:8px;">Threshold <?php echo number_format($spikeThreshold, 0); ?>% drop.</div>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <div class="section-title">Action Quality Review</div>
            <?php if (empty($actionQualityAlerts)): ?>
                <div class="muted">No potential over-moderation flagged.</div>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($actionQualityAlerts as $alert): ?>
                        <div>
                            <?php echo htmlspecialchars($alert['name'], ENT_QUOTES, 'UTF-8'); ?>
                            <span class="muted">(<?php echo number_format($alert['per1k'], 1); ?> per 1k msgs · <?php echo (int)$alert['actions']; ?> actions)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="muted" style="margin-top:8px;">Threshold <?php echo number_format($qualityThreshold, 0); ?> actions per 1k msgs.</div>
        </div>

        <div class="card">
            <div class="section-title">Top Contributors to Retention</div>
            <?php if (empty($retentionContributors)): ?>
                <div class="muted">Not enough data yet.</div>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($retentionContributors as $contrib): ?>
                        <div>
                            <?php echo htmlspecialchars($contrib['name'], ENT_QUOTES, 'UTF-8'); ?>
                            <span class="muted">(<?php echo number_format($contrib['score'] * 100, 1); ?>% index · <?php echo number_format($contrib['active_hours'], 1); ?>h)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="muted" style="margin-top:8px;">Proxy score based on consistency, impact, and active hours.</div>
        </div>
    </div>

    <div class="card">
        <div class="section-title">Shift Coverage Map</div>
        <div class="heatmap">
            <?php for ($d = 0; $d < 7; $d++): ?>
                <div class="heat-row">
                    <div class="heat-label"><?php echo htmlspecialchars($dayLabels[$d], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="heat-cells">
                        <?php for ($h = 0; $h < 24; $h++): ?>
                            <?php
                                $count = $coverageMap['mods'][$d][$h] ?? 0;
                                $intensity = $coverageMax > 0 ? ($count / $coverageMax) : 0;
                                $alpha = 0.12 + (0.88 * $intensity);
                                $label = $dayLabels[$d] . ' ' . str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00 · ' . $count . ' mods';
                            ?>
                            <div class="heat-cell" style="background: rgba(255, 122, 89, <?php echo number_format($alpha, 3); ?>);" title="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>"></div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
        <div class="heat-legend">
            <span>Low</span>
            <span>High coverage</span>
        </div>
        <div class="muted" style="margin-top:10px;">Coverage score <?php echo number_format($coverageScore, 1); ?>% · Gap threshold <?php echo (int)$coverageMinMods; ?> mod<?php echo $coverageMinMods === 1 ? '' : 's'; ?>/hour.</div>
        <?php if (!empty($coverageGaps)): ?>
            <div class="list" style="margin-top:10px;">
                <?php foreach ($coverageGaps as $gap): ?>
                    <div>
                        <?php echo htmlspecialchars($dayLabels[$gap['day']] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                        <?php echo str_pad((string)$gap['hour'], 2, '0', STR_PAD_LEFT); ?>:00
                        <span class="muted">(<?php echo (int)$gap['mods']; ?> mods · <?php echo (int)$gap['messages']; ?> msgs)</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="muted" style="margin-top:10px;">No coverage gaps detected.</div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
<?php
if ($renderPdf) {
    $html = ob_get_clean();
    $safeMonth = $range['month'] ?? ($month ?? 'current');
    $file = __DIR__ . '/../storage/reports/manager-digest-' . $chatId . '-' . $safeMonth . '.html';
    file_put_contents($file, $html);
    $pdfFile = preg_replace('/\\.html$/', '.pdf', $file);
    $pdf = new PdfService();
    if ($pdfFile && $pdf->htmlToPdf($file, $pdfFile)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($pdfFile) . '"');
        readfile($pdfFile);
    } else {
        header('Content-Type: text/plain');
        echo 'PDF engine not available. Please install wkhtmltopdf.';
    }
    exit;
}
?>
