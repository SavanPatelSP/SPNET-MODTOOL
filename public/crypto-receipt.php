<?php

$config = require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Services\PaymentService;

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

$amount = (float)($order['amount'] ?? 0);
$currency = $order['currency'] ?? 'USDT';
$network = $meta['network'] ?? 'TRC20';
$address = $meta['address'] ?? '';
$createdAt = $order['created_at'] ?? gmdate('Y-m-d H:i:s');
$receiptId = strtoupper(substr(hash('sha1', $orderId . $address), 0, 10));
$status = strtoupper((string)($order['status'] ?? 'PENDING'));
$brand = $config['report']['brand_name'] ?? 'SP NET MOD TOOL';

$download = isset($_GET['download']) && $_GET['download'] === '1';
if ($download) {
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="receipt-' . $orderId . '.html"');
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?php echo htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'); ?> Receipt</title>
<style>
body {
    margin: 0;
    font-family: "Avenir Next", "Avenir", "Trebuchet MS", Verdana, sans-serif;
    background: #f8fafc;
    color: #0f172a;
}
.wrap {
    max-width: 720px;
    margin: 32px auto;
    padding: 0 18px;
}
.card {
    background: #fff;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
    border: 1px solid #eef2f7;
}
.title {
    font-size: 20px;
    font-weight: 700;
    margin: 0;
}
.meta {
    margin-top: 6px;
    color: #64748b;
    font-size: 12px;
}
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px;
    margin-top: 18px;
}
.label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #64748b;
}
.value {
    font-size: 16px;
    font-weight: 700;
    margin-top: 6px;
    word-break: break-all;
}
.divider {
    height: 1px;
    background: #eef2f7;
    margin: 18px 0;
}
.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    background: #dcfce7;
    color: #166534;
    font-weight: 700;
    font-size: 11px;
}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="label"><?php echo htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'); ?></div>
        <h1 class="title">Payment Receipt</h1>
        <div class="meta">Receipt <?php echo htmlspecialchars($receiptId, ENT_QUOTES, 'UTF-8'); ?> · Order #<?php echo (int)$orderId; ?></div>
        <div class="divider"></div>
        <div class="grid">
            <div>
                <div class="label">Amount</div>
                <div class="value"><?php echo number_format($amount, 2); ?> <?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <div class="label">Network</div>
                <div class="value"><?php echo htmlspecialchars($network, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div>
                <div class="label">Status</div>
                <div class="value"><span class="badge"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span></div>
            </div>
            <div>
                <div class="label">Date</div>
                <div class="value"><?php echo htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?> UTC</div>
            </div>
        </div>
        <div class="divider"></div>
        <div class="label">Deposit Address</div>
        <div class="value"><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
</div>
</body>
</html>
