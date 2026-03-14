<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Services\SettingsService;
use App\Services\StatsService;
use App\Services\RewardService;

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

$chatId = $_GET['chat_id'] ?? ($dashboardConfig['default_chat_id'] ?? null);
$month = $_GET['month'] ?? null;
$budgetOverride = isset($_GET['budget']) ? (float)$_GET['budget'] : null;
$refresh = isset($_GET['refresh']) ? (int)$_GET['refresh'] : (int)($dashboardConfig['refresh_seconds'] ?? 0);
$refresh = max(0, $refresh);
$search = trim((string)($_GET['search'] ?? ''));
$minMessages = isset($_GET['min_messages']) ? (int)$_GET['min_messages'] : 0;
$onlyEligible = isset($_GET['only_eligible']);

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
} else {
    $bundle = $statsService->getMonthlyStats($chatId, $month);
    $budget = $budgetOverride ?? (float)($bundle['settings']['reward_budget'] ?? 0);
}

$ranked = $rewardService->rankAndReward($bundle['mods'], $budget);

$rewardMap = [];
$eligibleMap = [];
foreach ($ranked as $mod) {
    $rewardMap[$mod['user_id']] = $mod['reward'];
    if (array_key_exists('eligible', $mod)) {
        $eligibleMap[$mod['user_id']] = (bool)$mod['eligible'];
    }
}

$accent = $config['report']['accent_color'] ?? '#ff7a59';
$secondary = $config['report']['secondary_color'] ?? '#1f2a44';
$brand = $config['report']['brand_name'] ?? 'SP NET MOD TOOL';

$summary = $bundle['summary'];
$mods = $bundle['mods'];

foreach ($mods as &$mod) {
    $mod['eligible'] = $eligibleMap[$mod['user_id']] ?? true;
}
unset($mod);

usort($mods, fn($a, $b) => $b['score'] <=> $a['score']);

$modsFiltered = array_values(array_filter($mods, function (array $mod) use ($search, $minMessages, $onlyEligible): bool {
    if ($onlyEligible && empty($mod['eligible'])) {
        return false;
    }
    if ($minMessages > 0 && (int)($mod['messages'] ?? 0) < $minMessages) {
        return false;
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
foreach ($modsFiltered as $mod) {
    if ($mod['improvement'] !== null) {
        if ($mostImproved === null || $mod['improvement'] > $mostImproved['improvement']) {
            $mostImproved = $mod;
        }
    }
    if ($mostActions === null || ($mod['warnings'] + $mod['mutes'] + $mod['bans']) > ($mostActions['warnings'] + $mostActions['mutes'] + $mostActions['bans'])) {
        $mostActions = $mod;
    }
    if ($mostActive === null || $mod['active_minutes'] > $mostActive['active_minutes']) {
        $mostActive = $mod;
    }
}

$totalMessages = (int)($summary['messages'] ?? 0);
$externalMessages = (int)($summary['external_messages'] ?? 0);
$externalShare = $totalMessages > 0 ? round(($externalMessages / $totalMessages) * 100, 1) : 0;

$tz = new DateTimeZone($config['timezone'] ?? 'UTC');
$monthOptions = [];
$baseMonth = new DateTimeImmutable('first day of this month', $tz);
for ($i = 0; $i < 12; $i++) {
    $monthOptions[] = $baseMonth->modify("-{$i} month")->format('Y-m');
}

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
:root { --accent: <?php echo $accent; ?>; --secondary: <?php echo $secondary; ?>; }
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: "Avenir Next", "Avenir", "Trebuchet MS", Verdana, sans-serif;
    background: radial-gradient(circle at top right, #fdf4ff 0%, #eef3ff 35%, #f7f7fa 100%);
    color: var(--secondary);
}
.container {
    max-width: 1200px;
    margin: 32px auto 48px;
    padding: 0 20px;
}
.header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: #ffffff;
    padding: 18px 22px;
    border-radius: 16px;
    box-shadow: 0 20px 40px rgba(31, 42, 68, 0.12);
}
.header h1 {
    margin: 0;
    font-size: 22px;
}
.header .meta {
    font-size: 12px;
    color: #6b7280;
}
.controls {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}
select, input {
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    font-size: 13px;
}
.actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #fff7ed;
    color: #9a3412;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}
.button.secondary {
    background: #f8fafc;
    color: #334155;
}
.podium {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
    margin: 20px 0;
}
.podium-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 16px;
    border: 1px solid #eff2f5;
    box-shadow: 0 12px 30px rgba(31, 42, 68, 0.08);
}
.podium-card h4 {
    margin: 0 0 8px;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6b7280;
}
.podium-card .name {
    font-size: 16px;
    font-weight: 700;
}
.podium-card .score {
    margin-top: 6px;
    font-size: 13px;
    color: #9a3412;
}
.source-breakdown {
    margin-top: 6px;
    font-size: 11px;
    color: #6b7280;
}
.trend {
    font-weight: 600;
}
.trend-up { color: #16a34a; }
.trend-down { color: #dc2626; }
.trend-flat { color: #6b7280; }
.filter-note {
    margin-top: 10px;
    font-size: 12px;
    color: #6b7280;
}
.summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin: 20px 0;
}
.card {
    background: #ffffff;
    border-radius: 14px;
    padding: 16px;
    box-shadow: 0 12px 30px rgba(31, 42, 68, 0.08);
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
.table th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6b7280;
}
.rank {
    font-weight: 700;
    color: var(--accent);
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
.chat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 14px;
    margin-top: 18px;
}
.chat-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 16px;
    border: 1px solid #eff2f5;
    box-shadow: 0 14px 30px rgba(31, 42, 68, 0.08);
}
.chat-card h3 {
    margin: 0;
    font-size: 15px;
}
.chat-card .meta {
    margin-top: 4px;
}
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
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><?php echo htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'); ?> Live Dashboard</h1>
            <div class="meta"><?php echo htmlspecialchars($bundle['range']['label'], ENT_QUOTES, 'UTF-8'); ?> | <?php echo $isAll ? 'All Chats' : ('Chat ID ' . htmlspecialchars((string)$chatId, ENT_QUOTES, 'UTF-8')); ?></div>
        </div>
        <div class="controls">
            <form method="get">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars((string)$token, ENT_QUOTES, 'UTF-8'); ?>" />
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
                    Budget (optional)
                    <input type="text" name="budget" value="<?php echo htmlspecialchars((string)($budgetOverride ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                </label>
                <label>
                    Search
                    <input type="text" name="search" value="<?php echo htmlspecialchars((string)$search, ENT_QUOTES, 'UTF-8'); ?>" />
                </label>
                <label>
                    Min Msgs
                    <input type="text" name="min_messages" value="<?php echo htmlspecialchars((string)$minMessages, ENT_QUOTES, 'UTF-8'); ?>" />
                </label>
                <label>
                    Refresh (sec)
                    <input type="text" name="refresh" value="<?php echo htmlspecialchars((string)$refresh, ENT_QUOTES, 'UTF-8'); ?>" />
                </label>
                <label>
                    Eligible only
                    <input type="checkbox" name="only_eligible" value="1" <?php echo $onlyEligible ? 'checked' : ''; ?> />
                </label>
                <button type="submit">Update</button>
            </form>
            <div class="actions">
                <a class="button" href="export.php?type=html&amp;token=<?php echo htmlspecialchars((string)$token, ENT_QUOTES, 'UTF-8'); ?>&amp;chat_id=<?php echo htmlspecialchars((string)$chatId, ENT_QUOTES, 'UTF-8'); ?>&amp;month=<?php echo htmlspecialchars((string)($bundle['range']['month'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>&amp;budget=<?php echo htmlspecialchars((string)($budgetOverride ?? ''), ENT_QUOTES, 'UTF-8'); ?>">Download HTML</a>
                <a class="button secondary" href="export.php?type=csv&amp;token=<?php echo htmlspecialchars((string)$token, ENT_QUOTES, 'UTF-8'); ?>&amp;chat_id=<?php echo htmlspecialchars((string)$chatId, ENT_QUOTES, 'UTF-8'); ?>&amp;month=<?php echo htmlspecialchars((string)($bundle['range']['month'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>&amp;budget=<?php echo htmlspecialchars((string)($budgetOverride ?? ''), ENT_QUOTES, 'UTF-8'); ?>">Download CSV</a>
                <a class="button secondary" href="export.php?type=summary&amp;token=<?php echo htmlspecialchars((string)$token, ENT_QUOTES, 'UTF-8'); ?>&amp;month=<?php echo htmlspecialchars((string)($bundle['range']['month'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>&amp;budget=<?php echo htmlspecialchars((string)($budgetOverride ?? ''), ENT_QUOTES, 'UTF-8'); ?>">Download Summary</a>
            </div>
        </div>
    </div>

    <div class="podium">
        <?php foreach ($topMods as $idx => $mod): ?>
            <div class="podium-card">
                <h4>Top <?php echo $idx + 1; ?></h4>
                <div class="name"><?php echo htmlspecialchars($mod['display_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="score">Score <?php echo number_format($mod['score'], 2); ?></div>
                <div class="source-breakdown">Msgs <?php echo (int)$mod['messages']; ?> | Active <?php echo number_format($mod['active_minutes'] / 60, 1); ?>h</div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="podium">
        <div class="podium-card">
            <h4>Most Improved</h4>
            <div class="name"><?php echo htmlspecialchars($mostImproved['display_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
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
        <div class="podium-card">
            <h4>Most Actions</h4>
            <div class="name"><?php echo htmlspecialchars($mostActions['display_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="score">
                <?php
                    if (!empty($mostActions)) {
                        echo (int)(($mostActions['warnings'] ?? 0) + ($mostActions['mutes'] ?? 0) + ($mostActions['bans'] ?? 0)) . ' actions';
                    } else {
                        echo 'N/A';
                    }
                ?>
            </div>
        </div>
        <div class="podium-card">
            <h4>Most Active</h4>
            <div class="name"><?php echo htmlspecialchars($mostActive['display_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
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
    </div>

    <div class="summary">
        <div class="card">
            <h3>Mods Tracked</h3>
            <div class="value"><?php echo (int)($summary['total_mods'] ?? 0); ?></div>
        </div>
        <div class="card">
            <h3>Total Messages</h3>
            <div class="value"><?php echo (int)($summary['messages'] ?? 0); ?></div>
            <div class="source-breakdown">Bot <?php echo (int)($summary['internal_messages'] ?? 0); ?> | Ext <?php echo (int)($summary['external_messages'] ?? 0); ?></div>
        </div>
        <div class="card">
            <h3>Active Hours</h3>
            <div class="value"><?php echo number_format(($summary['active_minutes'] ?? 0) / 60, 1); ?>h</div>
            <div class="source-breakdown">Bot <?php echo number_format(($summary['internal_active_minutes'] ?? 0) / 60, 1); ?>h | Ext <?php echo number_format(($summary['external_active_minutes'] ?? 0) / 60, 1); ?>h</div>
        </div>
        <div class="card">
            <h3>Actions</h3>
            <div class="value"><?php echo (int)(($summary['warnings'] ?? 0) + ($summary['mutes'] ?? 0) + ($summary['bans'] ?? 0)); ?></div>
        </div>
        <div class="card">
            <h3>External Share</h3>
            <div class="value"><?php echo number_format($externalShare, 1); ?>%</div>
        </div>
        <div class="card">
            <h3>Budget</h3>
            <div class="value"><?php echo number_format($budget, 2); ?></div>
        </div>
        <div class="card">
            <h3>Avg Score</h3>
            <div class="value"><?php echo number_format((float)($summary['avg_score'] ?? 0), 2); ?></div>
        </div>
    </div>

    <div class="filter-note">
        Showing <?php echo count($modsFiltered); ?> of <?php echo count($mods); ?> mods |
        Eligible: <?php echo $eligibleCount; ?> (<?php echo number_format($eligibleRate, 1); ?>%)
    </div>

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
            <th>Eligible</th>
            <th>Reward</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $rank = 1;
        $topScore = $modsFiltered[0]['score'] ?? 1;
        foreach ($modsFiltered as $mod):
            $reward = $rewardMap[$mod['user_id']] ?? 0.0;
            $eligible = $eligibleMap[$mod['user_id']] ?? true;
            $scorePercent = $topScore > 0 ? ($mod['score'] / $topScore) * 100 : 0;
        ?>
        <tr>
            <td class="rank"><?php echo $rank; ?></td>
            <td><?php echo htmlspecialchars($mod['display_name'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td>
                <?php echo number_format($mod['score'], 2); ?>
                <div class="bar"><span style="width: <?php echo number_format($scorePercent, 2); ?>%"></span></div>
            </td>
            <td>
                <?php echo (int)$mod['messages']; ?>
                <div class="source-breakdown">Bot <?php echo (int)($mod['internal_messages'] ?? 0); ?> | Ext <?php echo (int)($mod['external_messages'] ?? 0); ?></div>
            </td>
            <td><?php echo (int)$mod['warnings']; ?></td>
            <td><?php echo (int)$mod['mutes']; ?></td>
            <td><?php echo (int)$mod['bans']; ?></td>
            <td>
                <?php echo number_format($mod['active_minutes'] / 60, 1); ?>h
                <div class="source-breakdown">Bot <?php echo number_format(($mod['internal_active_minutes'] ?? 0) / 60, 1); ?>h | Ext <?php echo number_format(($mod['external_active_minutes'] ?? 0) / 60, 1); ?>h</div>
            </td>
            <td><?php echo number_format($mod['membership_minutes'] / 60, 1); ?>h</td>
            <td><?php echo (int)$mod['days_active']; ?></td>
            <td>
                <?php
                    if ($mod['improvement'] === null) {
                        echo '<span class="trend trend-flat">N/A</span>';
                    } elseif ($mod['improvement'] >= 0) {
                        echo '<span class="trend trend-up">Up ' . number_format($mod['improvement'], 1) . '%</span>';
                    } else {
                        echo '<span class="trend trend-down">Down ' . number_format(abs($mod['improvement']), 1) . '%</span>';
                    }
                ?>
            </td>
            <td><?php echo $eligible ? 'Yes' : 'No'; ?></td>
            <td><?php echo number_format($reward, 2); ?></td>
        </tr>
        <?php
            $rank++;
        endforeach;
        ?>
        </tbody>
    </table>

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

    <div class="header" style="margin-top: 18px;">
        <div class="meta">Last refresh: <?php echo htmlspecialchars(gmdate('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'); ?> UTC</div>
        <div class="meta">
            Auto refresh:
            <?php echo $refresh > 0 ? ('every ' . htmlspecialchars((string)$refresh, ENT_QUOTES, 'UTF-8') . 's') : 'off'; ?>
        </div>
    </div>
</div>
</body>
</html>
