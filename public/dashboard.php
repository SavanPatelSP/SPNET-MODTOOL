<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Services\SettingsService;
use App\Services\StatsService;
use App\Services\RewardService;
use App\Services\RewardContextService;

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
$rewardContext = new RewardContextService($db, $config);

$chatId = $_GET['chat_id'] ?? ($dashboardConfig['default_chat_id'] ?? null);
$month = $_GET['month'] ?? null;
$budgetOverride = isset($_GET['budget']) ? (float)$_GET['budget'] : null;
$refresh = isset($_GET['refresh']) ? (int)$_GET['refresh'] : (int)($dashboardConfig['refresh_seconds'] ?? 0);
$refresh = max(0, $refresh);
$search = trim((string)($_GET['search'] ?? ''));
$minMessages = isset($_GET['min_messages']) ? (int)$_GET['min_messages'] : 0;
$minActions = isset($_GET['min_actions']) ? (int)$_GET['min_actions'] : 0;
$minActiveHours = isset($_GET['min_active_hours']) ? (float)$_GET['min_active_hours'] : 0.0;
$minScore = isset($_GET['min_score']) ? (float)$_GET['min_score'] : 0.0;
$minImpact = isset($_GET['min_impact']) ? (float)$_GET['min_impact'] : 0.0;
$minConsistency = isset($_GET['min_consistency']) ? (float)$_GET['min_consistency'] : 0.0;
$roleFilter = trim((string)($_GET['role'] ?? ''));
$onlyEligible = isset($_GET['only_eligible']);
$onlyImproving = isset($_GET['only_improving']);
$sortBy = $_GET['sort'] ?? 'score';
$sortDir = $_GET['dir'] ?? 'desc';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
$compact = isset($_GET['compact']);
$showSources = isset($_GET['show_sources']) ? (string)$_GET['show_sources'] === '1' : true;
if ($compact) {
    $showSources = false;
}

$chats = $db->fetchAll('SELECT id, title, type FROM chats ORDER BY title ASC');

if (!$chatId && !empty($chats)) {
    $chatId = $chats[0]['id'];
}

if (!$chatId) {
    echo 'No chats found yet. Add the bot to a group and send a message.';
    exit;
}

$isAll = ((string)$chatId === 'all');
$chatIds = [];
foreach ($chats as $chat) {
    if (in_array($chat['type'], ['group', 'supergroup'], true)) {
        $chatIds[] = $chat['id'];
    }
}

if ($isAll) {
    $bundle = $statsService->getMonthlyStatsForChats($chatIds, $month);
    $budget = $budgetOverride ?? 0.0;
    $context = ['premium' => false];
} else {
    $bundle = $statsService->getMonthlyStats($chatId, $month);
    $budget = $budgetOverride ?? (float)($bundle['settings']['reward_budget'] ?? 0);
    $context = $rewardContext->build($chatId, $bundle['range']['month']);
}

$ranked = $rewardService->rankAndReward($bundle['mods'], $budget, $context);
$premium = (bool)($context['premium'] ?? false);

$rewardMap = [];
$bonusMap = [];
$eligibleMap = [];
foreach ($ranked as $mod) {
    $rewardMap[$mod['user_id']] = $mod['reward'];
    $bonusMap[$mod['user_id']] = $mod['bonus'] ?? 0.0;
    if (array_key_exists('eligible', $mod)) {
        $eligibleMap[$mod['user_id']] = (bool)$mod['eligible'];
    }
}

$accent = $config['report']['accent_color'] ?? '#ff7a59';
$secondary = $config['report']['secondary_color'] ?? '#1f2a44';
$brand = $config['report']['brand_name'] ?? 'SP NET MOD TOOL';

$summary = $bundle['summary'];
$mods = $bundle['mods'];

$eligibilityRules = $config['eligibility'] ?? [];
$minDaysRule = (int)($eligibilityRules['min_days_active'] ?? 0);
$minMessagesRule = (int)($eligibilityRules['min_messages'] ?? 0);
$minScoreRule = (float)($eligibilityRules['min_score'] ?? 0);
$minActionsRule = (int)($eligibilityRules['min_actions'] ?? 0);
$minActiveHoursRule = (float)($eligibilityRules['min_active_hours'] ?? 0);

foreach ($mods as &$mod) {
    $mod['eligible'] = $eligibleMap[$mod['user_id']] ?? true;
    $mod['reward'] = $rewardMap[$mod['user_id']] ?? 0.0;
    $mod['bonus'] = $bonusMap[$mod['user_id']] ?? 0.0;
    $mod['actions'] = (int)(($mod['warnings'] ?? 0) + ($mod['mutes'] ?? 0) + ($mod['bans'] ?? 0));
    $mod['active_hours'] = ($mod['active_minutes'] ?? 0) / 60;
    $mod['impact_score'] = (float)($mod['impact_score'] ?? 0);
    $mod['consistency_index'] = (float)($mod['consistency_index'] ?? 0);
    $mod['role'] = $mod['role'] ?? '';
    $reasons = [];
    if ($minDaysRule > 0 && ($mod['days_active'] ?? 0) < $minDaysRule) {
        $reasons[] = 'Need ' . $minDaysRule . ' days';
    }
    if ($minMessagesRule > 0 && ($mod['messages'] ?? 0) < $minMessagesRule) {
        $reasons[] = 'Need ' . $minMessagesRule . ' msgs';
    }
    if ($minScoreRule > 0 && ($mod['score'] ?? 0) < $minScoreRule) {
        $reasons[] = 'Score < ' . $minScoreRule;
    }
    if ($minActionsRule > 0 && ($mod['actions'] ?? 0) < $minActionsRule) {
        $reasons[] = 'Need ' . $minActionsRule . ' actions';
    }
    if ($minActiveHoursRule > 0 && ($mod['active_hours'] ?? 0) < $minActiveHoursRule) {
        $reasons[] = 'Need ' . number_format($minActiveHoursRule, 1) . ' hrs';
    }
    $mod['eligibility_reason'] = implode(', ', $reasons);
}
unset($mod);

usort($mods, fn($a, $b) => $b['score'] <=> $a['score']);

$modsFiltered = array_values(array_filter($mods, function (array $mod) use ($search, $minMessages, $minActions, $minActiveHours, $minScore, $minImpact, $minConsistency, $roleFilter, $onlyEligible, $onlyImproving): bool {
    if ($onlyEligible && empty($mod['eligible'])) {
        return false;
    }
    if ($minMessages > 0 && (int)($mod['messages'] ?? 0) < $minMessages) {
        return false;
    }
    if ($minActions > 0 && (int)($mod['actions'] ?? 0) < $minActions) {
        return false;
    }
    if ($minActiveHours > 0 && (float)($mod['active_hours'] ?? 0) < $minActiveHours) {
        return false;
    }
    if ($minScore > 0 && (float)($mod['score'] ?? 0) < $minScore) {
        return false;
    }
    if ($minImpact > 0 && (float)($mod['impact_score'] ?? 0) < $minImpact) {
        return false;
    }
    if ($minConsistency > 0 && (float)($mod['consistency_index'] ?? 0) < $minConsistency) {
        return false;
    }
    if ($roleFilter !== '') {
        $role = strtolower(trim((string)($mod['role'] ?? '')));
        $needleRole = strtolower($roleFilter);
        if ($role === '' || strpos($role, $needleRole) === false) {
            return false;
        }
    }
    if ($onlyImproving) {
        $impr = $mod['improvement'] ?? null;
        if ($impr === null || $impr <= 0) {
            return false;
        }
    }
    if ($search !== '') {
        $lower = function (string $value): string {
            return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        };
        $pos = function (string $haystack, string $needle) {
            return function_exists('mb_strpos') ? mb_strpos($haystack, $needle) : strpos($haystack, $needle);
        };
        $needle = $lower($search);
        $haystack = $lower(($mod['display_name'] ?? '') . ' ' . ($mod['username'] ?? ''));
        if ($pos($haystack, $needle) === false) {
            return false;
        }
    }
    return true;
}));

$sortKey = static function (array $mod, string $sortBy): float {
    switch ($sortBy) {
        case 'messages':
            return (float)($mod['messages'] ?? 0);
        case 'actions':
            return (float)($mod['actions'] ?? 0);
        case 'active':
            return (float)($mod['active_minutes'] ?? 0);
        case 'days':
            return (float)($mod['days_active'] ?? 0);
        case 'reward':
            return (float)($mod['reward'] ?? 0);
        case 'impact':
            return (float)($mod['impact_score'] ?? 0);
        case 'consistency':
            return (float)($mod['consistency_index'] ?? 0);
        case 'bonus':
            return (float)($mod['bonus'] ?? 0);
        case 'improvement':
            return (float)($mod['improvement'] ?? -INF);
        default:
            return (float)($mod['score'] ?? 0);
    }
};

usort($modsFiltered, function (array $a, array $b) use ($sortBy, $sortDir, $sortKey): int {
    $av = $sortKey($a, $sortBy);
    $bv = $sortKey($b, $sortBy);
    if ($av === $bv) {
        return 0;
    }
    $cmp = $av <=> $bv;
    return $sortDir === 'asc' ? $cmp : -$cmp;
});

if ($limit > 0) {
    $modsFiltered = array_slice($modsFiltered, 0, $limit);
}

$topMods = array_slice($modsFiltered, 0, 3);

$eligibleCount = 0;
foreach ($mods as $mod) {
    if (!empty($mod['eligible'])) {
        $eligibleCount++;
    }
}
$eligibleRate = count($mods) > 0 ? round(($eligibleCount / count($mods)) * 100, 1) : 0;

$mostImproved = null;
$mostActions = null;
$mostActive = null;
$mostImpact = null;
$mostConsistent = null;
foreach ($modsFiltered as $mod) {
    if ($mod['improvement'] !== null) {
        if ($mostImproved === null || $mod['improvement'] > $mostImproved['improvement']) {
            $mostImproved = $mod;
        }
    }
    if ($mostActions === null || ($mod['actions'] ?? 0) > ($mostActions['actions'] ?? 0)) {
        $mostActions = $mod;
    }
    if ($mostActive === null || ($mod['active_minutes'] ?? 0) > ($mostActive['active_minutes'] ?? 0)) {
        $mostActive = $mod;
    }
    if ($mostImpact === null || ($mod['impact_score'] ?? 0) > ($mostImpact['impact_score'] ?? 0)) {
        $mostImpact = $mod;
    }
    if ($mostConsistent === null || ($mod['consistency_index'] ?? 0) > ($mostConsistent['consistency_index'] ?? 0)) {
        $mostConsistent = $mod;
    }
}

$totalMessages = (int)($summary['messages'] ?? 0);
$externalMessages = (int)($summary['external_messages'] ?? 0);
$externalShare = $totalMessages > 0 ? round(($externalMessages / $totalMessages) * 100, 1) : 0;
$internalMessageShare = $totalMessages > 0 ? round((($summary['internal_messages'] ?? 0) / $totalMessages) * 100, 1) : 0;
$externalMessageShare = $totalMessages > 0 ? round(100 - $internalMessageShare, 1) : 0;

$totalActiveMinutes = (int)($summary['active_minutes'] ?? 0);
$internalActiveShare = $totalActiveMinutes > 0 ? round((($summary['internal_active_minutes'] ?? 0) / $totalActiveMinutes) * 100, 1) : 0;
$externalActiveShare = $totalActiveMinutes > 0 ? round(100 - $internalActiveShare, 1) : 0;

$totalActions = (int)(($summary['warnings'] ?? 0) + ($summary['mutes'] ?? 0) + ($summary['bans'] ?? 0));

$rewardTotal = 0.0;
$rewardValues = [];
$rewardedCount = 0;
$totalDaysActive = 0;
$improvedCount = 0;
$declinedCount = 0;
$flatCount = 0;
$noTrendCount = 0;
$eligibilityGaps = [
    'days' => 0,
    'messages' => 0,
    'score' => 0,
    'actions' => 0,
    'active_hours' => 0,
];

foreach ($mods as $mod) {
    $reward = (float)($mod['reward'] ?? 0.0);
    $rewardTotal += $reward;
    if ($reward > 0) {
        $rewardedCount++;
        $rewardValues[] = $reward;
    }
    $totalDaysActive += (int)($mod['days_active'] ?? 0);
    $impr = $mod['improvement'] ?? null;
    if ($impr === null) {
        $noTrendCount++;
    } elseif ($impr > 0) {
        $improvedCount++;
    } elseif ($impr < 0) {
        $declinedCount++;
    } else {
        $flatCount++;
    }
    if ($minDaysRule > 0 && ($mod['days_active'] ?? 0) < $minDaysRule) {
        $eligibilityGaps['days']++;
    }
    if ($minMessagesRule > 0 && ($mod['messages'] ?? 0) < $minMessagesRule) {
        $eligibilityGaps['messages']++;
    }
    if ($minScoreRule > 0 && ($mod['score'] ?? 0) < $minScoreRule) {
        $eligibilityGaps['score']++;
    }
    if ($minActionsRule > 0 && ($mod['actions'] ?? 0) < $minActionsRule) {
        $eligibilityGaps['actions']++;
    }
    if ($minActiveHoursRule > 0 && ($mod['active_hours'] ?? 0) < $minActiveHoursRule) {
        $eligibilityGaps['active_hours']++;
    }
}

$rewardMedian = 0.0;
$rewardMax = 0.0;
if (!empty($rewardValues)) {
    sort($rewardValues);
    $rewardMax = (float)$rewardValues[count($rewardValues) - 1];
    $mid = (int)floor((count($rewardValues) - 1) / 2);
    if (count($rewardValues) % 2 === 0) {
        $rewardMedian = ($rewardValues[$mid] + $rewardValues[$mid + 1]) / 2;
    } else {
        $rewardMedian = $rewardValues[$mid];
    }
}

$avgReward = $rewardedCount > 0 ? $rewardTotal / $rewardedCount : 0.0;
$remainingBudget = $budget > 0 ? round($budget - $rewardTotal, 2) : null;
$budgetUsedPercent = $budget > 0 ? min(100, max(0, ($rewardTotal / $budget) * 100)) : 0;
$bonusPercent = (float)($config['reward']['kpi_bonus_percent'] ?? 0);
$bonusPool = $budget > 0 ? round($budget * $bonusPercent, 2) : 0.0;

$avgDaysActive = count($mods) > 0 ? round($totalDaysActive / count($mods), 1) : 0.0;
$avgActiveHours = count($mods) > 0 ? round(($totalActiveMinutes / 60) / count($mods), 1) : 0.0;
$messagesPerActiveHour = $totalActiveMinutes > 0 ? round($totalMessages / ($totalActiveMinutes / 60), 1) : 0.0;
$actionsPer1k = $totalMessages > 0 ? round(($totalActions / $totalMessages) * 1000, 1) : 0.0;

$topScore = $modsFiltered[0]['score'] ?? 1;
$totalScore = 0.0;
foreach ($mods as $mod) {
    $totalScore += (float)($mod['score'] ?? 0);
}
$top3Score = 0.0;
foreach (array_slice($mods, 0, 3) as $mod) {
    $top3Score += (float)($mod['score'] ?? 0);
}
$top3Share = $totalScore > 0 ? round(($top3Score / $totalScore) * 100, 1) : 0;

$rewardSorted = $mods;
usort($rewardSorted, fn($a, $b) => ($b['reward'] ?? 0) <=> ($a['reward'] ?? 0));
$top3RewardSum = 0.0;
foreach (array_slice($rewardSorted, 0, 3) as $mod) {
    $top3RewardSum += (float)($mod['reward'] ?? 0);
}
$top3RewardShare = $rewardTotal > 0 ? round(($top3RewardSum / $rewardTotal) * 100, 1) : 0;

$filtersApplied = [];
if ($search !== '') {
    $filtersApplied[] = 'Search: ' . $search;
}
if ($minMessages > 0) {
    $filtersApplied[] = 'Min msgs: ' . $minMessages;
}
if ($minActions > 0) {
    $filtersApplied[] = 'Min actions: ' . $minActions;
}
if ($minActiveHours > 0) {
    $filtersApplied[] = 'Min active hrs: ' . $minActiveHours;
}
if ($minScore > 0) {
    $filtersApplied[] = 'Min score: ' . $minScore;
}
if ($minImpact > 0) {
    $filtersApplied[] = 'Min impact: ' . $minImpact;
}
if ($minConsistency > 0) {
    $filtersApplied[] = 'Min consistency: ' . $minConsistency . '%';
}
if ($roleFilter !== '') {
    $filtersApplied[] = 'Role: ' . $roleFilter;
}
if ($onlyEligible) {
    $filtersApplied[] = 'Eligible only';
}
if ($onlyImproving) {
    $filtersApplied[] = 'Improving only';
}
if ($limit > 0) {
    $filtersApplied[] = 'Limit: ' . $limit;
}
if ($compact) {
    $filtersApplied[] = 'Compact view';
}
if ($showSources) {
    $filtersApplied[] = 'Source breakdown';
}
$filtersText = empty($filtersApplied) ? 'No extra filters applied.' : implode(' • ', $filtersApplied);

$eligibilityParts = [];
if ($minDaysRule > 0) {
    $eligibilityParts[] = $minDaysRule . ' days';
}
if ($minMessagesRule > 0) {
    $eligibilityParts[] = $minMessagesRule . ' messages';
}
if ($minScoreRule > 0) {
    $eligibilityParts[] = 'score ≥ ' . $minScoreRule;
}
if ($minActionsRule > 0) {
    $eligibilityParts[] = $minActionsRule . ' actions';
}
if ($minActiveHoursRule > 0) {
    $eligibilityParts[] = number_format($minActiveHoursRule, 1) . ' active hrs';
}
$eligibilityText = empty($eligibilityParts) ? 'No minimums set.' : ('Eligibility rules: ' . implode(', ', $eligibilityParts) . '.');

$sortLabels = [
    'score' => 'Score',
    'impact' => 'Impact',
    'consistency' => 'Consistency',
    'messages' => 'Messages',
    'actions' => 'Actions',
    'active' => 'Active minutes',
    'days' => 'Active days',
    'reward' => 'Reward',
    'bonus' => 'Bonus',
    'improvement' => 'Improvement',
];
$sortLabel = $sortLabels[$sortBy] ?? ucfirst($sortBy);

$tz = new DateTimeZone($config['timezone'] ?? 'UTC');
$monthOptions = [];
$baseMonth = new DateTimeImmutable('first day of this month', $tz);
for ($i = 0; $i < 12; $i++) {
    $monthOptions[] = $baseMonth->modify("-{$i} month")->format('Y-m');
}
$lastUpdated = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i');

$chatTitle = null;
foreach ($chats as $chat) {
    if ((string)$chat['id'] === (string)$chatId) {
        $chatTitle = $chat['title'] ?: (string)$chat['id'];
        break;
    }
}
if ($isAll) {
    $chatTitle = 'All Chats';
}

$resetParams = [
    'token' => $token,
    'chat_id' => $chatId,
    'month' => $bundle['range']['month'] ?? '',
];
if ($budgetOverride !== null) {
    $resetParams['budget'] = $budgetOverride;
}
$resetUrl = 'dashboard.php?' . http_build_query($resetParams);

$exportBase = [
    'token' => $token,
    'chat_id' => $chatId,
    'month' => $bundle['range']['month'] ?? '',
];
if ($budgetOverride !== null) {
    $exportBase['budget'] = $budgetOverride;
}
$htmlExportUrl = 'export.php?' . http_build_query(array_merge(['type' => 'html'], $exportBase));
$csvExportUrl = 'export.php?' . http_build_query(array_merge(['type' => 'csv'], $exportBase));
$pdfExportUrl = 'export.php?' . http_build_query(array_merge(['type' => 'pdf'], $exportBase));
$execExportUrl = 'export.php?' . http_build_query(array_merge(['type' => 'executive'], $exportBase));
$trendExportUrl = 'export.php?' . http_build_query(array_merge(['type' => 'trend'], $exportBase));
$summaryExportUrl = 'export.php?' . http_build_query(array_merge(['type' => 'summary', 'token' => $token, 'month' => $bundle['range']['month'] ?? ''], $budgetOverride !== null ? ['budget' => $budgetOverride] : []));
$managerDigestUrl = 'manager-digest.php?' . http_build_query(array_merge(['token' => $token, 'chat_id' => $chatId, 'month' => $bundle['range']['month'] ?? ''], $budgetOverride !== null ? ['budget' => $budgetOverride] : []));
$importUrl = 'import.php?' . http_build_query([
    'token' => $token,
    'chat_id' => $chatId,
    'month' => $bundle['range']['month'] ?? '',
]);

$colCount = $compact ? 14 : 17;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<?php if ($refresh > 0): ?>
<meta http-equiv="refresh" content="<?php echo htmlspecialchars((string)$refresh, ENT_QUOTES, 'UTF-8'); ?>" />
<?php endif; ?>
<title><?php echo htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'); ?> Dashboard</title>
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
    max-width: 1250px;
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
.hero h1 {
    margin: 6px 0 8px;
    font-size: 24px;
}
.kicker {
    font-size: 11px;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--muted);
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
.hero-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
    align-items: flex-end;
}
.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: flex-end;
}
.hint {
    font-size: 11px;
    color: var(--muted);
    max-width: 260px;
    text-align: right;
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
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
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
details {
    margin-top: 12px;
    background: #f8fafc;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 10px 12px;
}
summary {
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    color: #334155;
    list-style: none;
}
summary::-webkit-details-marker { display: none; }
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 10px;
    margin-top: 10px;
}
.check-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-top: 10px;
}
label.check {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    text-transform: none;
    letter-spacing: 0;
    color: var(--secondary);
}
.panel-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}
.panel-note {
    margin-top: 8px;
    font-size: 11px;
    color: var(--muted);
}
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
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
.button.secondary {
    background: #f8fafc;
    color: #334155;
}
.button.ghost {
    background: transparent;
    color: #334155;
}
.summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin: 20px 0;
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
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #9a3412;
}
.card .value {
    font-size: 22px;
    font-weight: 700;
}
.subtext {
    margin-top: 6px;
    font-size: 12px;
    color: var(--muted);
}
.progress {
    margin-top: 8px;
    height: 7px;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
}
.progress span {
    display: block;
    height: 100%;
    background: var(--accent);
}
.progress.split span.internal { background: #60a5fa; }
.progress.split span.external { background: #fbbf24; }
.highlight-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
    margin: 10px 0 20px;
}
.highlight-card {
    background: var(--surface);
    border-radius: 14px;
    padding: 16px;
    border: 1px solid #eff2f5;
    box-shadow: var(--shadow);
}
.highlight-card h4 {
    margin: 0 0 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted);
}
.mod-name {
    font-size: 14px;
    font-weight: 700;
}
.highlight-card .mod-name {
    font-size: 16px;
}
.highlight-card .score {
    margin-top: 6px;
    font-size: 13px;
    color: #9a3412;
}
.source-breakdown {
    margin-top: 6px;
    font-size: 11px;
    color: var(--muted);
}
.trend {
    font-weight: 600;
}
.trend-up { color: #16a34a; }
.trend-down { color: #dc2626; }
.trend-flat { color: #6b7280; }
.insights-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}
.filter-note {
    margin-top: 12px;
    font-size: 12px;
    color: var(--muted);
}
.section-title {
    margin: 20px 0 12px;
    font-size: 12px;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--muted);
}
.table-wrap {
    background: var(--surface);
    border-radius: 16px;
    overflow-x: auto;
    box-shadow: var(--shadow);
    border: 1px solid #eef2f7;
}
.table {
    width: 100%;
    min-width: 1180px;
    border-collapse: collapse;
}
.table th, .table td {
    padding: 10px 12px;
    border-bottom: 1px solid #eef0f4;
    text-align: left;
    font-size: 13px;
}
.table th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted);
    background: #f8fafc;
    position: sticky;
    top: 0;
    z-index: 1;
}
.table tr:hover { background: #f8fafc; }
.rank {
    font-weight: 700;
    color: var(--accent);
}
.rank-top { color: #b45309; }
.badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 6px;
    border-radius: 999px;
    background: #fff7ed;
    color: #9a3412;
    font-size: 10px;
    font-weight: 700;
    margin-left: 6px;
}
.pill {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
}
.pill.good {
    background: #dcfce7;
    color: #166534;
}
.pill.bad {
    background: #fee2e2;
    color: #991b1b;
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
.mini-bar {
    margin-top: 6px;
    height: 5px;
    background: #eef0f4;
    border-radius: 999px;
    overflow: hidden;
    display: flex;
}
.mini-bar span {
    display: block;
    height: 100%;
}
.mini-bar span.internal { background: #60a5fa; }
.mini-bar span.external { background: #fbbf24; }
.chat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 14px;
    margin-top: 18px;
}
.chat-card {
    background: var(--surface);
    border-radius: 16px;
    padding: 16px;
    border: 1px solid #eff2f5;
    box-shadow: var(--shadow);
}
.chat-card h3 {
    margin: 0;
    font-size: 15px;
}
.chat-card .meta { margin-top: 4px; }
.chat-card .stats {
    margin-top: 12px;
    display: grid;
    gap: 8px;
}
.chat-card .stats div {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
}
.footer {
    margin-top: 18px;
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: var(--muted);
}
@media (max-width: 900px) {
    .hero {
        grid-template-columns: 1fr;
        text-align: left;
    }
    .hero-actions {
        align-items: flex-start;
    }
    .hint {
        text-align: left;
    }
}
@media (max-width: 640px) {
    .control-grid {
        grid-template-columns: 1fr;
    }
    .chips {
        gap: 6px;
    }
    .table {
        min-width: 980px;
    }
}
</style>
</head>
<body>
<div class="container">
    <div class="hero">
        <div>
            <div class="kicker"><?php echo htmlspecialchars($bundle['range']['label'], ENT_QUOTES, 'UTF-8'); ?></div>
            <h1><?php echo htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'); ?> Dashboard</h1>
            <div class="meta"><?php echo htmlspecialchars((string)$chatTitle, ENT_QUOTES, 'UTF-8'); ?><?php if (!$isAll): ?> | Chat ID <?php echo htmlspecialchars((string)$chatId, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></div>
            <div class="meta">Last updated <?php echo htmlspecialchars($lastUpdated, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($tz->getName(), ENT_QUOTES, 'UTF-8'); ?>) · Auto refresh <?php echo $refresh > 0 ? ('every ' . htmlspecialchars((string)$refresh, ENT_QUOTES, 'UTF-8') . 's') : 'off'; ?></div>
            <div class="chips">
                <span class="chip">Mods <?php echo (int)($summary['total_mods'] ?? 0); ?></span>
                <span class="chip">Msgs <?php echo (int)($summary['messages'] ?? 0); ?></span>
                <span class="chip">Actions <?php echo $totalActions; ?></span>
                <span class="chip">Eligible <?php echo $eligibleCount; ?>/<?php echo count($mods); ?></span>
                <span class="chip">External Share <?php echo number_format($externalShare, 1); ?>%</span>
                <?php if (!$isAll): ?>
                    <span class="chip"><?php echo $premium ? 'Premium' : 'Free'; ?> Plan</span>
                <?php endif; ?>
                <?php if ($refresh > 0): ?>
                    <span class="chip">Live Refresh</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="hero-actions">
            <div class="action-buttons">
                <a class="button primary" href="<?php echo htmlspecialchars($htmlExportUrl, ENT_QUOTES, 'UTF-8'); ?>">Reward Sheet (HTML)</a>
                <a class="button secondary" href="<?php echo htmlspecialchars($csvExportUrl, ENT_QUOTES, 'UTF-8'); ?>">Reward Sheet (CSV)</a>
                <?php if ($premium): ?>
                    <a class="button secondary" href="<?php echo htmlspecialchars($pdfExportUrl, ENT_QUOTES, 'UTF-8'); ?>">Reward Sheet (PDF)</a>
                    <a class="button ghost" href="<?php echo htmlspecialchars($execExportUrl, ENT_QUOTES, 'UTF-8'); ?>">Executive Summary</a>
                    <a class="button ghost" href="<?php echo htmlspecialchars($trendExportUrl, ENT_QUOTES, 'UTF-8'); ?>">Trend Report</a>
                    <a class="button ghost" href="<?php echo htmlspecialchars($importUrl, ENT_QUOTES, 'UTF-8'); ?>">Import Wizard</a>
                <?php endif; ?>
                <a class="button ghost" href="<?php echo htmlspecialchars($summaryExportUrl, ENT_QUOTES, 'UTF-8'); ?>">Multi-Chat Summary</a>
                <?php if (!$isAll): ?>
                    <a class="button ghost" href="<?php echo htmlspecialchars($managerDigestUrl, ENT_QUOTES, 'UTF-8'); ?>">Manager Digest</a>
                <?php endif; ?>
            </div>
            <div class="hint">Exports use the selected chat and month. Filters only change the leaderboard view.</div>
        </div>
    </div>

    <div class="panel">
        <form method="get">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars((string)$token, ENT_QUOTES, 'UTF-8'); ?>" />
            <div class="control-grid">
                <label>
                    Chat
                    <select name="chat_id">
                        <option value="all" <?php echo $isAll ? 'selected' : ''; ?>>All Chats</option>
                        <?php foreach ($chats as $chat): ?>
                            <option value="<?php echo htmlspecialchars((string)$chat['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ((string)$chat['id'] === (string)$chatId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($chat['title'] ?: $chat['id']), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Month (YYYY-MM)
                    <input list="month-list" type="text" name="month" value="<?php echo htmlspecialchars((string)($bundle['range']['month'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                    <datalist id="month-list">
                        <?php foreach ($monthOptions as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label>
                    Budget Override
                    <input type="number" step="0.01" name="budget" value="<?php echo htmlspecialchars((string)($budgetOverride ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                </label>
                <label>
                    Sort By
                    <select name="sort">
                        <?php foreach ($sortLabels as $value => $label): ?>
                            <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $sortBy === $value ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Order
                    <select name="dir">
                        <option value="desc" <?php echo $sortDir === 'desc' ? 'selected' : ''; ?>>High → Low</option>
                        <option value="asc" <?php echo $sortDir === 'asc' ? 'selected' : ''; ?>>Low → High</option>
                    </select>
                </label>
            </div>
            <details>
                <summary>Advanced Filters</summary>
                <div class="filter-grid">
                    <label>
                        Search
                        <input type="text" name="search" value="<?php echo htmlspecialchars((string)$search, ENT_QUOTES, 'UTF-8'); ?>" />
                    </label>
                    <label>
                        Min Messages
                        <input type="number" name="min_messages" value="<?php echo htmlspecialchars((string)$minMessages, ENT_QUOTES, 'UTF-8'); ?>" />
                    </label>
                    <label>
                        Min Actions
                        <input type="number" name="min_actions" value="<?php echo htmlspecialchars((string)$minActions, ENT_QUOTES, 'UTF-8'); ?>" />
                    </label>
                    <label>
                        Min Active Hours
                        <input type="number" step="0.1" name="min_active_hours" value="<?php echo htmlspecialchars((string)$minActiveHours, ENT_QUOTES, 'UTF-8'); ?>" />
                    </label>
                    <label>
                        Min Score
                        <input type="number" step="0.1" name="min_score" value="<?php echo htmlspecialchars((string)$minScore, ENT_QUOTES, 'UTF-8'); ?>" />
                    </label>
                    <label>
                        Min Impact
                        <input type="number" step="0.1" name="min_impact" value="<?php echo htmlspecialchars((string)$minImpact, ENT_QUOTES, 'UTF-8'); ?>" />
                    </label>
                    <label>
                        Min Consistency %
                        <input type="number" step="0.1" name="min_consistency" value="<?php echo htmlspecialchars((string)$minConsistency, ENT_QUOTES, 'UTF-8'); ?>" />
                    </label>
                    <label>
                        Role contains
                        <input type="text" name="role" value="<?php echo htmlspecialchars((string)$roleFilter, ENT_QUOTES, 'UTF-8'); ?>" />
                    </label>
                    <label>
                        Limit
                        <input type="number" name="limit" value="<?php echo htmlspecialchars((string)$limit, ENT_QUOTES, 'UTF-8'); ?>" />
                    </label>
                    <label>
                        Refresh (sec)
                        <input type="number" name="refresh" value="<?php echo htmlspecialchars((string)$refresh, ENT_QUOTES, 'UTF-8'); ?>" />
                    </label>
                </div>
                <div class="check-grid">
                    <label class="check">
                        <input type="checkbox" name="only_eligible" value="1" <?php echo $onlyEligible ? 'checked' : ''; ?> />
                        Eligible only
                    </label>
                    <label class="check">
                        <input type="checkbox" name="only_improving" value="1" <?php echo $onlyImproving ? 'checked' : ''; ?> />
                        Improving only
                    </label>
                    <label class="check">
                        <input type="checkbox" name="compact" value="1" <?php echo $compact ? 'checked' : ''; ?> />
                        Compact view
                    </label>
                    <input type="hidden" name="show_sources" value="0" />
                    <label class="check">
                        <input type="checkbox" name="show_sources" value="1" <?php echo $showSources ? 'checked' : ''; ?> />
                        Show source breakdown
                    </label>
                </div>
            </details>
            <div class="panel-actions">
                <button type="submit" class="button primary">Update Dashboard</button>
                <a class="button ghost" href="<?php echo htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8'); ?>">Reset Filters</a>
            </div>
            <div class="panel-note"><?php echo htmlspecialchars($eligibilityText, ENT_QUOTES, 'UTF-8'); ?></div>
        </form>
    </div>

    <div class="summary">
        <div class="card">
            <h3>Mods Tracked</h3>
            <div class="value"><?php echo (int)($summary['total_mods'] ?? 0); ?></div>
            <div class="subtext">Avg days active <?php echo number_format($avgDaysActive, 1); ?></div>
        </div>
        <div class="card">
            <h3>Reward Used</h3>
            <div class="value"><?php echo number_format($rewardTotal, 2); ?></div>
            <?php if ($budget > 0): ?>
                <div class="subtext">Remaining <?php echo number_format((float)$remainingBudget, 2); ?> of <?php echo number_format($budget, 2); ?></div>
                <div class="progress"><span style="width: <?php echo number_format($budgetUsedPercent, 1); ?>%"></span></div>
            <?php else: ?>
                <div class="subtext">No budget set for this chat.</div>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>Total Messages</h3>
            <div class="value"><?php echo (int)($summary['messages'] ?? 0); ?></div>
            <div class="subtext">Avg per mod <?php echo count($mods) > 0 ? number_format($totalMessages / count($mods), 1) : '0'; ?></div>
            <?php if ($showSources): ?>
                <div class="source-breakdown">Bot <?php echo (int)($summary['internal_messages'] ?? 0); ?> | External (CK/Combot) <?php echo (int)($summary['external_messages'] ?? 0); ?></div>
                <div class="progress split">
                    <span class="internal" style="width: <?php echo number_format($internalMessageShare, 1); ?>%"></span>
                    <span class="external" style="width: <?php echo number_format($externalMessageShare, 1); ?>%"></span>
                </div>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>Active Hours</h3>
            <div class="value"><?php echo number_format(($summary['active_minutes'] ?? 0) / 60, 1); ?>h</div>
            <div class="subtext">Avg per mod <?php echo number_format($avgActiveHours, 1); ?>h · <?php echo number_format($messagesPerActiveHour, 1); ?> msgs/hr</div>
            <?php if ($showSources): ?>
                <div class="source-breakdown">Bot <?php echo number_format(($summary['internal_active_minutes'] ?? 0) / 60, 1); ?>h | External (CK/Combot) <?php echo number_format(($summary['external_active_minutes'] ?? 0) / 60, 1); ?>h</div>
                <div class="progress split">
                    <span class="internal" style="width: <?php echo number_format($internalActiveShare, 1); ?>%"></span>
                    <span class="external" style="width: <?php echo number_format($externalActiveShare, 1); ?>%"></span>
                </div>
            <?php endif; ?>
        </div>
        <div class="card">
            <h3>Actions</h3>
            <div class="value"><?php echo $totalActions; ?></div>
            <div class="subtext"><?php echo number_format($actionsPer1k, 1); ?> actions / 1k msgs</div>
        </div>
        <div class="card">
            <h3>Eligibility</h3>
            <div class="value"><?php echo number_format($eligibleRate, 1); ?>%</div>
            <div class="subtext"><?php echo $eligibleCount; ?> eligible of <?php echo count($mods); ?></div>
        </div>
        <div class="card">
            <h3>Avg Score</h3>
            <div class="value"><?php echo number_format((float)($summary['avg_score'] ?? 0), 2); ?></div>
            <div class="subtext">Top 3 share <?php echo number_format($top3Share, 1); ?>%</div>
        </div>
        <div class="card">
            <h3>Avg Impact</h3>
            <div class="value"><?php echo number_format((float)($summary['avg_impact'] ?? 0), 2); ?></div>
            <div class="subtext">Impact favors actions + recency.</div>
        </div>
        <div class="card">
            <h3>Avg Consistency</h3>
            <div class="value"><?php echo number_format((float)($summary['avg_consistency'] ?? 0), 1); ?>%</div>
            <div class="subtext">Based on active days in month.</div>
        </div>
    </div>

    <div class="section-title">Highlights</div>
    <div class="highlight-grid">
        <?php foreach ($topMods as $idx => $mod): ?>
            <div class="highlight-card">
                <h4>Top <?php echo $idx + 1; ?></h4>
                <div class="mod-name"><?php echo htmlspecialchars($mod['display_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="score">Score <?php echo number_format($mod['score'], 2); ?></div>
                <div class="source-breakdown">Msgs <?php echo (int)$mod['messages']; ?> | Active <?php echo number_format($mod['active_minutes'] / 60, 1); ?>h</div>
            </div>
        <?php endforeach; ?>
        <div class="highlight-card">
            <h4>Most Improved</h4>
            <div class="mod-name"><?php echo htmlspecialchars($mostImproved['display_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="score">
                <?php
                    if (!empty($mostImproved) && $mostImproved['improvement'] !== null) {
                        echo 'Up ' . number_format($mostImproved['improvement'], 1) . '%';
                    } else {
                        echo 'N/A';
                    }
                ?>
            </div>
        </div>
        <div class="highlight-card">
            <h4>Most Actions</h4>
            <div class="mod-name"><?php echo htmlspecialchars($mostActions['display_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="score">
                <?php
                    if (!empty($mostActions)) {
                        echo (int)($mostActions['actions'] ?? 0) . ' actions';
                    } else {
                        echo 'N/A';
                    }
                ?>
            </div>
        </div>
        <div class="highlight-card">
            <h4>Most Active</h4>
            <div class="mod-name"><?php echo htmlspecialchars($mostActive['display_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="score">
                <?php
                    if (!empty($mostActive)) {
                        echo number_format(($mostActive['active_minutes'] ?? 0) / 60, 1) . 'h';
                    } else {
                        echo 'N/A';
                    }
                ?>
            </div>
        </div>
        <div class="highlight-card">
            <h4>Highest Impact</h4>
            <div class="mod-name"><?php echo htmlspecialchars($mostImpact['display_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="score">
                <?php
                    if (!empty($mostImpact)) {
                        echo number_format((float)($mostImpact['impact_score'] ?? 0), 2);
                    } else {
                        echo 'N/A';
                    }
                ?>
            </div>
        </div>
        <div class="highlight-card">
            <h4>Most Consistent</h4>
            <div class="mod-name"><?php echo htmlspecialchars($mostConsistent['display_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="score">
                <?php
                    if (!empty($mostConsistent)) {
                        echo number_format((float)($mostConsistent['consistency_index'] ?? 0), 1) . '%';
                    } else {
                        echo 'N/A';
                    }
                ?>
            </div>
        </div>
    </div>

    <div class="section-title">Insights</div>
    <div class="insights-grid">
        <div class="card">
            <h3>Reward Distribution</h3>
            <div class="subtext">Rewarded mods <?php echo $rewardedCount; ?></div>
            <div class="subtext">Avg reward <?php echo number_format($avgReward, 2); ?></div>
            <?php if ($bonusPool > 0): ?>
                <div class="subtext">KPI bonus pool <?php echo number_format($bonusPool, 2); ?></div>
            <?php endif; ?>
            <div class="subtext">Median <?php echo number_format($rewardMedian, 2); ?> · Max <?php echo number_format($rewardMax, 2); ?></div>
            <div class="subtext">Top 3 reward share <?php echo number_format($top3RewardShare, 1); ?>%</div>
        </div>
        <div class="card">
            <h3>Activity Quality</h3>
            <div class="subtext">Avg active hours <?php echo number_format($avgActiveHours, 1); ?>h</div>
            <div class="subtext">Messages per hour <?php echo number_format($messagesPerActiveHour, 1); ?></div>
            <div class="subtext">Actions per 1k msgs <?php echo number_format($actionsPer1k, 1); ?></div>
        </div>
        <div class="card">
            <h3>Trend Mix</h3>
            <div class="subtext">Improving <?php echo $improvedCount; ?> · Declining <?php echo $declinedCount; ?></div>
            <div class="subtext">Flat <?php echo $flatCount; ?> · No trend <?php echo $noTrendCount; ?></div>
        </div>
        <div class="card">
            <h3>Eligibility Gaps</h3>
            <?php if ($minDaysRule === 0 && $minMessagesRule === 0 && $minScoreRule === 0): ?>
                <div class="subtext">No minimums set.</div>
            <?php else: ?>
                <?php if ($minDaysRule > 0): ?>
                    <div class="subtext">Below <?php echo $minDaysRule; ?> days: <?php echo $eligibilityGaps['days']; ?></div>
                <?php endif; ?>
                <?php if ($minMessagesRule > 0): ?>
                    <div class="subtext">Below <?php echo $minMessagesRule; ?> msgs: <?php echo $eligibilityGaps['messages']; ?></div>
                <?php endif; ?>
                <?php if ($minScoreRule > 0): ?>
                    <div class="subtext">Score under <?php echo $minScoreRule; ?>: <?php echo $eligibilityGaps['score']; ?></div>
                <?php endif; ?>
                <?php if ($minActionsRule > 0): ?>
                    <div class="subtext">Below <?php echo $minActionsRule; ?> actions: <?php echo $eligibilityGaps['actions']; ?></div>
                <?php endif; ?>
                <?php if ($minActiveHoursRule > 0): ?>
                    <div class="subtext">Below <?php echo number_format($minActiveHoursRule, 1); ?> hrs: <?php echo $eligibilityGaps['active_hours']; ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="filter-note">
        Showing <?php echo count($modsFiltered); ?> of <?php echo count($mods); ?> mods · Sort: <?php echo htmlspecialchars($sortLabel, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($sortDir, ENT_QUOTES, 'UTF-8'); ?>) · <?php echo htmlspecialchars($filtersText, ENT_QUOTES, 'UTF-8'); ?>
    </div>

    <div class="section-title">Leaderboard</div>
    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>#</th>
                <th>Mod</th>
                <th>Role</th>
                <th>Score</th>
                <th>Impact</th>
                <th>Consist</th>
                <?php if ($compact): ?>
                    <th>Actions</th>
                <?php else: ?>
                    <th>Warn</th>
                    <th>Mute</th>
                    <th>Ban</th>
                <?php endif; ?>
                <th>Msgs</th>
                <th>Active</th>
                <?php if (!$compact): ?>
                    <th>Member</th>
                <?php endif; ?>
                <th>Days</th>
                <th>Trend</th>
                <th>Bonus</th>
                <th>Eligible</th>
                <th>Reward</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($modsFiltered)): ?>
                <tr>
                    <td colspan="<?php echo $colCount; ?>">No mods match the current filters.</td>
                </tr>
            <?php else: ?>
                <?php
                $rank = 1;
                foreach ($modsFiltered as $mod):
                    $reward = $rewardMap[$mod['user_id']] ?? 0.0;
                    $eligible = $eligibleMap[$mod['user_id']] ?? true;
                    $scorePercent = $topScore > 0 ? ($mod['score'] / $topScore) * 100 : 0;
                    $showUsername = !empty($mod['username']) && strpos((string)$mod['display_name'], '@') !== 0;
                    $internalMessages = (int)($mod['internal_messages'] ?? 0);
                    $externalMessages = (int)($mod['external_messages'] ?? 0);
                    $messageTotal = $internalMessages + $externalMessages;
                    $messageInternalShare = $messageTotal > 0 ? round(($internalMessages / $messageTotal) * 100, 1) : 0;
                    $messageExternalShare = $messageTotal > 0 ? round(100 - $messageInternalShare, 1) : 0;
                    $internalActive = (float)($mod['internal_active_minutes'] ?? 0);
                    $externalActive = (float)($mod['external_active_minutes'] ?? 0);
                    $activeTotal = $internalActive + $externalActive;
                    $activeInternalShare = $activeTotal > 0 ? round(($internalActive / $activeTotal) * 100, 1) : 0;
                    $activeExternalShare = $activeTotal > 0 ? round(100 - $activeInternalShare, 1) : 0;
                ?>
                <tr>
                    <td class="rank <?php echo $rank <= 3 ? 'rank-top' : ''; ?>">
                        <?php echo $rank; ?>
                        <?php if ($rank <= 3): ?><span class="badge">Top</span><?php endif; ?>
                    </td>
                    <td>
                        <div class="mod-name"><?php echo htmlspecialchars($mod['display_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if ($showUsername): ?>
                            <div class="subtext">@<?php echo htmlspecialchars($mod['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($mod['role'] !== '' ? $mod['role'] : '-', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php echo number_format($mod['score'], 2); ?>
                        <div class="bar"><span style="width: <?php echo number_format($scorePercent, 2); ?>%"></span></div>
                    </td>
                    <td><?php echo number_format((float)($mod['impact_score'] ?? 0), 2); ?></td>
                    <td><?php echo number_format((float)($mod['consistency_index'] ?? 0), 1); ?>%</td>
                    <?php if ($compact): ?>
                        <td><?php echo (int)$mod['actions']; ?></td>
                    <?php else: ?>
                        <td><?php echo (int)$mod['warnings']; ?></td>
                        <td><?php echo (int)$mod['mutes']; ?></td>
                        <td><?php echo (int)$mod['bans']; ?></td>
                    <?php endif; ?>
                    <td>
                        <?php echo (int)$mod['messages']; ?>
                        <?php if ($showSources): ?>
                            <div class="source-breakdown">Bot <?php echo $internalMessages; ?> | External (CK/Combot) <?php echo $externalMessages; ?></div>
                            <div class="mini-bar">
                                <span class="internal" style="width: <?php echo number_format($messageInternalShare, 1); ?>%"></span>
                                <span class="external" style="width: <?php echo number_format($messageExternalShare, 1); ?>%"></span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo number_format($mod['active_minutes'] / 60, 1); ?>h
                        <?php if ($showSources): ?>
                            <div class="source-breakdown">Bot <?php echo number_format($internalActive / 60, 1); ?>h | External (CK/Combot) <?php echo number_format($externalActive / 60, 1); ?>h</div>
                            <div class="mini-bar">
                                <span class="internal" style="width: <?php echo number_format($activeInternalShare, 1); ?>%"></span>
                                <span class="external" style="width: <?php echo number_format($activeExternalShare, 1); ?>%"></span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <?php if (!$compact): ?>
                        <td><?php echo number_format($mod['membership_minutes'] / 60, 1); ?>h</td>
                    <?php endif; ?>
                    <td><?php echo (int)$mod['days_active']; ?></td>
                    <td>
                        <?php
                            if ($mod['improvement'] === null) {
                                echo '<span class="trend trend-flat">N/A</span>';
                            } elseif ($mod['improvement'] >= 0) {
                                echo '<span class="trend trend-up">Up &uarr; ' . number_format($mod['improvement'], 1) . '%</span>';
                            } else {
                                echo '<span class="trend trend-down">Down &darr; ' . number_format(abs($mod['improvement']), 1) . '%</span>';
                            }
                            $trend3m = $mod['trend_3m'] ?? null;
                            $trend3mLabel = 'N/A';
                            if ($trend3m !== null) {
                                $trend3mLabel = ($trend3m >= 0 ? '+' : '') . number_format($trend3m, 1) . '%';
                            }
                        ?>
                        <div class="subtext">3m <?php echo $trend3mLabel; ?></div>
                    </td>
                    <td><?php echo number_format((float)($mod['bonus'] ?? 0), 2); ?></td>
                    <td>
                        <?php if ($eligible): ?>
                            <span class="pill good">Yes</span>
                        <?php else: ?>
                            <span class="pill bad" title="<?php echo htmlspecialchars($mod['eligibility_reason'] ?: 'Not eligible', ENT_QUOTES, 'UTF-8'); ?>">No</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo number_format($reward, 2); ?></td>
                </tr>
                <?php
                    $rank++;
                endforeach;
                ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($isAll && !empty($bundle['chats'])): ?>
        <div class="section-title">Per Chat Breakdown</div>
        <div class="chat-grid">
            <?php foreach ($bundle['chats'] as $chat): ?>
                <?php $chatSummary = $chat['summary'] ?? []; ?>
                <div class="chat-card">
                    <h3><?php echo htmlspecialchars($chat['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <div class="meta">Chat ID <?php echo htmlspecialchars((string)$chat['chat_id'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="stats">
                        <div><span>Messages</span><strong><?php echo (int)($chatSummary['messages'] ?? 0); ?></strong></div>
                        <div><span>Actions</span><strong><?php echo (int)(($chatSummary['warnings'] ?? 0) + ($chatSummary['mutes'] ?? 0) + ($chatSummary['bans'] ?? 0)); ?></strong></div>
                        <div><span>Active Hours</span><strong><?php echo number_format(($chatSummary['active_minutes'] ?? 0) / 60, 1); ?>h</strong></div>
                        <div><span>Budget</span><strong><?php echo number_format((float)($chat['budget'] ?? 0), 2); ?></strong></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="footer">
        <div>Last refresh: <?php echo htmlspecialchars($lastUpdated, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($tz->getName(), ENT_QUOTES, 'UTF-8'); ?>)</div>
        <div>Auto refresh: <?php echo $refresh > 0 ? ('every ' . htmlspecialchars((string)$refresh, ENT_QUOTES, 'UTF-8') . 's') : 'off'; ?></div>
    </div>
</div>
</body>
</html>
