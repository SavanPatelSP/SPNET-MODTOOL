<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Services\ExternalImportService;
use App\Services\SubscriptionService;
use App\Telegram;
use App\Logger;

$dashboardConfig = $config['dashboard'] ?? [];
$token = $dashboardConfig['token'] ?? null;
$provided = $_GET['token'] ?? $_POST['token'] ?? ($_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? null);

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
$token = $config['bot_token'] ?? null;
if ($token && $token !== 'YOUR_TELEGRAM_BOT_TOKEN') {
    $tg = new Telegram($token);
    Logger::initChannel($tg, $config);
}
$subscriptions = new SubscriptionService($db, $config);
$importer = new ExternalImportService($db, $config);

$chatId = $_POST['chat_id'] ?? ($_GET['chat_id'] ?? null);
$month = $_POST['month'] ?? ($_GET['month'] ?? null);
$source = $_POST['source'] ?? 'chatkeeper';
$replace = !empty($_POST['replace']);
$result = null;

$chats = $db->fetchAll("SELECT id, title, type FROM chats WHERE type IN ('group','supergroup')");
if ($chatId === null && count($chats) === 1) {
    $chatId = $chats[0]['id'];
}

if ($chatId && !$subscriptions->isPremium($chatId)) {
    echo 'Premium feature. Upgrade the chat plan to use the import wizard.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$chatId) {
        $result = ['ok' => false, 'error' => 'Missing chat id.'];
    } elseif (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $result = ['ok' => false, 'error' => 'Upload failed.'];
    } else {
        $tmp = $_FILES['csv']['tmp_name'];
        $chatId = (int)$chatId;
        if ($source === 'combot') {
            $result = $importer->importCombot($tmp, $chatId, $month, $replace);
        } else {
            $result = $importer->importChatkeeper($tmp, $chatId, $month, $replace);
        }

        if ($result['ok'] ?? false) {
            $logging = $config['logging'] ?? [];
            if (!empty($logging['log_imports'])) {
                Logger::info('Import wizard: ' . ($result['source'] ?? 'source') . ' chat ' . $chatId . ' month ' . ($result['month'] ?? '') . ' rows ' . ($result['imported'] ?? 0));
            }
        } else {
            Logger::error('Import wizard failed for chat ' . $chatId . ': ' . ($result['error'] ?? 'unknown'));
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Import Wizard</title>
<style>
body { font-family: "Avenir Next", "Avenir", "Trebuchet MS", Verdana, sans-serif; background: #f8fafc; margin: 0; }
.container { max-width: 760px; margin: 32px auto; padding: 0 20px 40px; }
.card { background: #fff; border-radius: 14px; padding: 18px; box-shadow: 0 16px 32px rgba(15,23,42,0.08); }
label { display: block; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; color: #64748b; margin-top: 12px; }
select, input { width: 100%; padding: 10px; border-radius: 10px; border: 1px solid #e2e8f0; margin-top: 6px; }
.actions { margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap; }
.button { padding: 8px 12px; border-radius: 10px; border: none; background: #ff7a59; color: #fff; font-weight: 600; cursor: pointer; }
.note { margin-top: 10px; font-size: 12px; color: #64748b; }
.result { margin-top: 16px; font-size: 13px; }
</style>
</head>
<body>
<div class="container">
    <div class="card">
        <h2>Import Wizard</h2>
        <p class="note">Upload ChatKeeper or Combot CSV and attach it to a chat/month.</p>

        <?php if ($result): ?>
            <div class="result">
                <?php if ($result['ok'] ?? false): ?>
                    Imported <?php echo (int)($result['imported'] ?? 0); ?> rows for <?php echo htmlspecialchars($result['month'] ?? '', ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($result['source'] ?? '', ENT_QUOTES, 'UTF-8'); ?>).
                <?php else: ?>
                    Error: <?php echo htmlspecialchars($result['error'] ?? 'Import failed.', ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars((string)$token, ENT_QUOTES, 'UTF-8'); ?>" />
            <label>Chat</label>
            <select name="chat_id">
                <?php foreach ($chats as $chat): ?>
                    <option value="<?php echo htmlspecialchars((string)$chat['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ((string)$chat['id'] === (string)$chatId) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(($chat['title'] ?: $chat['id']), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Month (YYYY-MM)</label>
            <input type="text" name="month" value="<?php echo htmlspecialchars((string)$month, ENT_QUOTES, 'UTF-8'); ?>" />

            <label>Source</label>
            <select name="source">
                <option value="chatkeeper" <?php echo $source === 'chatkeeper' ? 'selected' : ''; ?>>ChatKeeper</option>
                <option value="combot" <?php echo $source === 'combot' ? 'selected' : ''; ?>>Combot</option>
            </select>

            <label>CSV File</label>
            <input type="file" name="csv" accept=".csv" />

            <label>
                <input type="checkbox" name="replace" value="1" <?php echo $replace ? 'checked' : ''; ?> />
                Replace existing data for this month/source
            </label>

            <div class="actions">
                <button class="button" type="submit">Import CSV</button>
                <a class="note" href="dashboard.php?token=<?php echo htmlspecialchars((string)$token, ENT_QUOTES, 'UTF-8'); ?>">Back to Dashboard</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
