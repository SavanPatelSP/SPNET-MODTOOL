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
$refresh = (int)($dashboardConfig['refresh_seconds'] ?? 60);

$chats = $db->fetchAll('SELECT id, title, type FROM chats ORDER BY title ASC');

if (!$chatId && !empty($chats)) {
    $chatId = $chats[0]['id'];
}

if (!$chatId) {
    echo 'No chats found yet. Add the bot to a group and send a message.';
    exit;
}

$bundle = $statsService->getMonthlyStats($chatId, $month);
$budget = (float)($bundle['settings']['reward_budget'] ?? 0);
$ranked = $rewardService->rankAndReward($bundle['mods'], $budget);

$rewardMap = [];
foreach ($ranked as $mod) {
    $rewardMap[$mod['user_id']] = $mod['reward'];
}

$accent = $config['report']['accent_color'] ?? '#ff7a59';
$secondary = $config['report']['secondary_color'] ?? '#1f2a44';
$brand = $config['report']['brand_name'] ?? 'SP NET MOD TOOL';

$summary = $bundle['summary'];
$mods = $bundle['mods'];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta http-equiv="refresh" content="<?php echo htmlspecialchars((string)$refresh, ENT_QUOTES, 'UTF-8'); ?>" />
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
}
select, input {
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    font-size: 13px;
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
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><?php echo htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'); ?> Live Dashboard</h1>
            <div class="meta"><?php echo htmlspecialchars($bundle['range']['label'], ENT_QUOTES, 'UTF-8'); ?> | Chat ID <?php echo htmlspecialchars((string)$chatId, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="controls">
            <form method="get">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars((string)$token, ENT_QUOTES, 'UTF-8'); ?>" />
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
                    Month (YYYY-MM)
                    <input type="text" name="month" value="<?php echo htmlspecialchars((string)($bundle['range']['month'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                </label>
                <button type="submit">Update</button>
            </form>
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
        </div>
        <div class="card">
            <h3>Active Hours</h3>
            <div class="value"><?php echo number_format(($summary['active_minutes'] ?? 0) / 60, 1); ?>h</div>
        </div>
        <div class="card">
            <h3>Actions</h3>
            <div class="value"><?php echo (int)(($summary['warnings'] ?? 0) + ($summary['mutes'] ?? 0) + ($summary['bans'] ?? 0)); ?></div>
        </div>
        <div class="card">
            <h3>Budget</h3>
            <div class="value"><?php echo number_format($budget, 2); ?></div>
        </div>
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
            <th>Reward</th>
        </tr>
        </thead>
        <tbody>
        <?php
        $rank = 1;
        $topScore = $mods[0]['score'] ?? 1;
        foreach ($mods as $mod):
            $reward = $rewardMap[$mod['user_id']] ?? 0.0;
            $scorePercent = $topScore > 0 ? ($mod['score'] / $topScore) * 100 : 0;
        ?>
        <tr>
            <td class="rank"><?php echo $rank; ?></td>
            <td><?php echo htmlspecialchars($mod['display_name'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td>
                <?php echo number_format($mod['score'], 2); ?>
                <div class="bar"><span style="width: <?php echo number_format($scorePercent, 2); ?>%"></span></div>
            </td>
            <td><?php echo (int)$mod['messages']; ?></td>
            <td><?php echo (int)$mod['warnings']; ?></td>
            <td><?php echo (int)$mod['mutes']; ?></td>
            <td><?php echo (int)$mod['bans']; ?></td>
            <td><?php echo number_format($mod['active_minutes'] / 60, 1); ?>h</td>
            <td><?php echo number_format($mod['membership_minutes'] / 60, 1); ?>h</td>
            <td><?php echo (int)$mod['days_active']; ?></td>
            <td><?php echo number_format($reward, 2); ?></td>
        </tr>
        <?php
            $rank++;
        endforeach;
        ?>
        </tbody>
    </table>

    <div class="header" style="margin-top: 18px;">
        <div class="meta">Last refresh: <?php echo htmlspecialchars(gmdate('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8'); ?> UTC</div>
        <div class="meta">Auto refresh: every <?php echo htmlspecialchars((string)$refresh, ENT_QUOTES, 'UTF-8'); ?>s</div>
    </div>
</div>
</body>
</html>
