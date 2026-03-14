<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Services\PaymentService;
use App\Services\SubscriptionService;
use App\Services\AuditLogService;

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

$orderId = isset($_GET['order']) ? (int)$_GET['order'] : 0;
if ($orderId <= 0) {
    echo 'Missing order id.';
    exit;
}

$db = new Database($config['db']);
$payments = new PaymentService($db, $config);
$subs = new SubscriptionService($db, $config);
$audit = new AuditLogService($db);

$order = $payments->getById($orderId);
if (!$order || ($order['method'] ?? '') !== 'crypto') {
    echo 'Order not found.';
    exit;
}

$meta = [];
if (!empty($order['meta'])) {
    $decoded = json_decode((string)$order['meta'], true);
    if (is_array($decoded)) {
        $meta = $decoded;
    }
}

$testMode = $payments->isTestMode();
$status = $order['status'] ?? 'pending';
$paid = $status === 'test_paid' || $status === 'paid' || $status === 'successful';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $testMode && !$paid) {
    $payments->updateStatus($orderId, 'test_paid');
    $status = 'test_paid';
    $paid = true;

    $plan = $order['plan'] ?? null;
    $days = isset($order['days']) ? (int)$order['days'] : null;
    $chatId = (int)($order['chat_id'] ?? 0);
    if ($plan && $chatId) {
        $subs->setPlan($chatId, (string)$plan, $days);
    }
    $audit->log('crypto_test_paid', 0, $chatId, ['order_id' => $orderId]);
}

$amount = (float)($order['amount'] ?? 0);
$currency = $order['currency'] ?? 'USDT';
$network = $meta['network'] ?? 'TRC20';
$address = $meta['address'] ?? 'T' . str_repeat('X', 33);
$createdAt = $order['created_at'] ?? gmdate('Y-m-d H:i:s');
$expiryTs = strtotime($createdAt . ' +15 minutes');
$expired = $expiryTs !== false && time() > $expiryTs;
$txId = hash('sha256', $orderId . '|' . $createdAt);

$title = $config['report']['brand_name'] ?? 'SP NET MOD TOOL';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?> Crypto Checkout</title>
<style>
body {
    margin: 0;
    font-family: "Avenir Next", "Avenir", "Trebuchet MS", Verdana, sans-serif;
    background: radial-gradient(circle at top right, #f1f5ff 0%, #fef3e5 45%, #f8fafc 100%);
    color: #1f2a44;
}
.container {
    max-width: 860px;
    margin: 32px auto 60px;
    padding: 0 20px;
}
.header {
    background: #0f172a;
    color: #fff;
    padding: 18px 22px;
    border-radius: 16px;
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.15);
}
.header h1 { margin: 0; font-size: 22px; }
.header .meta { font-size: 12px; color: #cbd5f5; margin-top: 6px; }
.card {
    background: #fff;
    border-radius: 16px;
    padding: 20px 22px;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
    border: 1px solid #eef2f7;
    margin-top: 18px;
}
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
}
.label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #64748b;
}
.value {
    font-size: 18px;
    font-weight: 700;
    margin-top: 6px;
}
.pill {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 11px;
    font-weight: 700;
    background: #e2e8f0;
    color: #0f172a;
}
.pill.good { background: #dcfce7; color: #166534; }
.pill.warn { background: #fee2e2; color: #991b1b; }
.address {
    background: #0f172a;
    color: #f8fafc;
    padding: 12px 14px;
    border-radius: 12px;
    font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
    font-size: 13px;
    word-break: break-all;
}
.actions {
    margin-top: 16px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.btn {
    border: none;
    padding: 10px 16px;
    border-radius: 10px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
}
.btn.primary { background: #ff7a59; color: #fff; }
.btn.ghost { background: #e2e8f0; color: #0f172a; }
.note {
    margin-top: 10px;
    font-size: 12px;
    color: #64748b;
}
.divider { height: 1px; background: #eef2f7; margin: 16px 0; }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Crypto Checkout (Sandbox)</h1>
        <div class="meta">Order #<?php echo (int)$orderId; ?> · Created <?php echo htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?> UTC</div>
    </div>

    <div class="card">
        <div class="grid">
            <div>
                <div class="label">Amount Due</div>
                <div class="value"><?php echo number_format($amount, 2); ?> <?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="note">Network fee not included · Test mode only</div>
            </div>
            <div>
                <div class="label">Network</div>
                <div class="value"><?php echo htmlspecialchars($network, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="note">Confirm chain before sending.</div>
            </div>
            <div>
                <div class="label">Status</div>
                <div class="value">
                    <?php if ($paid): ?>
                        <span class="pill good">Paid (Sandbox)</span>
                    <?php else: ?>
                        <span class="pill <?php echo $expired ? 'warn' : ''; ?>"><?php echo $expired ? 'Expired' : 'Awaiting Payment'; ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="divider"></div>
        <div class="label">Deposit Address</div>
        <div class="address"><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="note">Send exactly the amount to this address in the sandbox.</div>

        <div class="actions">
            <?php if ($testMode): ?>
                <?php if (!$paid): ?>
                    <form method="post">
                        <button class="btn primary" type="submit">Simulate Payment (Test)</button>
                    </form>
                <?php endif; ?>
                <a class="btn ghost" href="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">Refresh</a>
            <?php endif; ?>
        </div>
        <div class="note">Transaction ID (fake): <?php echo substr($txId, 0, 16); ?>…</div>
    </div>
</div>
</body>
</html>
