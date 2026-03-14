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
[$firstName, $lastName] = array_pad(explode(' ', (string)($order['payer_name'] ?? ''), 2), 2, '');

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
$displayAmount = number_format($amount, 2) . ' ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8');
$expiresAt = $expiryTs ? gmdate('Y-m-d H:i:s', $expiryTs) : '';
$receiptId = strtoupper(substr(hash('sha1', $orderId . $address), 0, 10));
$receiptUrl = 'crypto-receipt.php?token=' . urlencode((string)$token) . '&order=' . urlencode((string)$orderId) . '&download=1';
$qrPayload = $currency . ':' . $address . '?amount=' . number_format($amount, 6, '.', '') . '&network=' . $network;
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($qrPayload);

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
    transition: background 0.3s ease, color 0.3s ease;
}
body.dark {
    background: radial-gradient(circle at top right, #0f172a 0%, #111827 50%, #0b1220 100%);
    color: #e2e8f0;
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
.dark .header {
    background: #111827;
    color: #f8fafc;
}
.header h1 { margin: 0; font-size: 22px; }
.header .meta { font-size: 12px; color: #cbd5f5; margin-top: 6px; }
.header .brand {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.toggle {
    border: 1px solid rgba(148, 163, 184, 0.35);
    background: rgba(15, 23, 42, 0.2);
    color: #e2e8f0;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}
body.dark .toggle {
    background: rgba(148, 163, 184, 0.12);
    color: #f8fafc;
    border-color: rgba(148, 163, 184, 0.45);
}
.brand-title {
    font-size: 11px;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: #94a3b8;
}
.brand-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
.card {
    background: #fff;
    border-radius: 16px;
    padding: 20px 22px;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.08);
    border: 1px solid #eef2f7;
    margin-top: 18px;
}
.layout {
    display: grid;
    grid-template-columns: minmax(0, 1.3fr) minmax(0, 0.7fr);
    gap: 18px;
}
.summary-card {
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 16px;
    padding: 18px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.15);
}
.summary-card .label { color: #94a3b8; }
.summary-card .value { color: #fff; }
.summary-card .divider { background: rgba(148, 163, 184, 0.2); }
.dark .summary-card {
    background: #111827;
}
.dark .card {
    background: #0b1220;
    border-color: #1f2937;
    box-shadow: 0 20px 45px rgba(2, 6, 23, 0.5);
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
body.dark .label { color: #94a3b8; }
.value {
    font-size: 18px;
    font-weight: 700;
    margin-top: 6px;
}
.value.large {
    font-size: 22px;
}
.value.success {
    color: #16a34a;
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
body.dark .pill { background: rgba(148, 163, 184, 0.2); color: #f8fafc; }
.pill.good { background: #dcfce7; color: #166534; }
.pill.warn { background: #fee2e2; color: #991b1b; }
.pill.info { background: #dbeafe; color: #1d4ed8; }
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
.actions.center {
    justify-content: flex-start;
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
.btn.dark { background: #0f172a; color: #fff; }
body.dark .btn.ghost { background: rgba(148, 163, 184, 0.2); color: #f8fafc; }
body.dark .btn.dark { background: #111827; }
.note {
    margin-top: 10px;
    font-size: 12px;
    color: #64748b;
}
body.dark .note { color: #94a3b8; }
.divider { height: 1px; background: #eef2f7; margin: 16px 0; }
body.dark .divider { background: rgba(148, 163, 184, 0.2); }
.steps {
    display: grid;
    gap: 10px;
    margin-top: 12px;
}
.step {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    font-size: 13px;
    color: #334155;
}
body.dark .step { color: #cbd5f5; }
.step .dot {
    width: 22px;
    height: 22px;
    border-radius: 999px;
    background: #e2e8f0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 12px;
    color: #0f172a;
}
.qr {
    width: 150px;
    height: 150px;
    border: 8px solid #fff;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15);
    border-radius: 14px;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
}
.qr img {
    width: 100%;
    height: 100%;
    border-radius: 8px;
    display: block;
}
.qr-wrap {
    display: flex;
    gap: 18px;
    align-items: center;
    flex-wrap: wrap;
}
.timer {
    font-weight: 700;
    color: #b45309;
}
.chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #f1f5f9;
    color: #0f172a;
    font-size: 11px;
    font-weight: 700;
}
body.dark .chip { background: rgba(148, 163, 184, 0.2); color: #f8fafc; }
.row {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    font-size: 13px;
    color: #334155;
}
body.dark .row { color: #cbd5f5; }
.muted { color: #64748b; }
.timeline {
    display: grid;
    gap: 10px;
    margin-top: 12px;
}
.timeline-item {
    display: grid;
    grid-template-columns: 16px 1fr;
    gap: 10px;
    align-items: flex-start;
    font-size: 13px;
    color: #334155;
}
body.dark .timeline-item { color: #cbd5f5; }
.timeline-dot {
    width: 12px;
    height: 12px;
    border-radius: 999px;
    background: #e2e8f0;
    margin-top: 3px;
}
.timeline-dot.active { background: #22c55e; }
.notice {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px;
    font-size: 12px;
    color: #475569;
}
body.dark .notice {
    background: rgba(15, 23, 42, 0.7);
    border-color: #1f2937;
    color: #cbd5f5;
}
.confirmations {
    margin-top: 14px;
}
.confirm-bar {
    margin-top: 8px;
    height: 8px;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.25);
    overflow: hidden;
}
.confirm-bar-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #38bdf8, #22c55e);
    transition: width 0.6s ease;
}
.email-receipt {
    margin-top: 14px;
}
.email-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 8px;
}
.email-row input {
    flex: 1 1 220px;
    min-width: 200px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    font-size: 13px;
}
body.dark .email-row input {
    background: #0b1220;
    border-color: #1f2937;
    color: #f8fafc;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    background: rgba(34, 197, 94, 0.12);
    color: #16a34a;
    font-size: 11px;
    font-weight: 700;
}
.checkmark {
    width: 40px;
    height: 40px;
    border-radius: 999px;
    background: #22c55e;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 900;
    margin-bottom: 10px;
}
@media (max-width: 900px) {
    .layout {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="brand">
            <div>
                <div class="brand-title">Secure Checkout</div>
                <h1>Crypto Checkout</h1>
            </div>
            <div class="brand-actions">
                <button class="toggle" id="themeToggle" type="button" aria-pressed="false">Dark mode</button>
                <div class="chip">Powered by SP NET MOD TOOL</div>
            </div>
        </div>
        <div class="meta">Order #<?php echo (int)$orderId; ?> · Created <?php echo htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?> UTC</div>
    </div>

    <div class="layout">
        <div>
            <div class="card">
                <?php if ($paid): ?>
                    <div class="checkmark">✓</div>
                    <div class="label">Payment Complete</div>
                    <div class="value large success">Your transaction is confirmed.</div>
                    <div class="note">Thank you for your purchase. A receipt has been generated below.</div>
                    <div class="divider"></div>
                    <div class="grid">
                        <div>
                            <div class="label">Receipt</div>
                            <div class="value"><?php echo htmlspecialchars($receiptId, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div>
                            <div class="label">Amount Paid</div>
                            <div class="value"><?php echo $displayAmount; ?></div>
                        </div>
                        <div>
                            <div class="label">Status</div>
                            <div class="value"><span class="pill good">Completed</span></div>
                        </div>
                    </div>
                    <div class="divider"></div>
                    <div class="actions">
                        <a class="btn primary" href="<?php echo htmlspecialchars($receiptUrl, ENT_QUOTES, 'UTF-8'); ?>">Download Receipt</a>
                    </div>
                    <div class="email-receipt">
                        <div class="label">Email receipt</div>
                        <div class="email-row">
                            <input type="email" id="receipt-email" placeholder="name@example.com" />
                            <button class="btn ghost" type="button" id="send-receipt">Email Receipt</button>
                        </div>
                        <div class="note" id="receipt-status"></div>
                    </div>
                <?php endif; ?>
                <div class="grid">
                    <div>
                        <div class="label">Amount Due</div>
                        <div class="value large"><?php echo $displayAmount; ?></div>
                        <div class="note">Network fee not included · Secure checkout</div>
                    </div>
                    <div>
                        <div class="label">Network</div>
                        <div class="value"><?php echo htmlspecialchars($network, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="note">Send only on <?php echo htmlspecialchars($network, ENT_QUOTES, 'UTF-8'); ?>.</div>
                    </div>
                    <div>
                        <div class="label">Status</div>
                        <div class="value">
                            <?php if ($paid): ?>
                                <span class="pill good">Paid</span>
                            <?php else: ?>
                                <span class="pill <?php echo $expired ? 'warn' : 'info'; ?>"><?php echo $expired ? 'Expired' : 'Awaiting Payment'; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="divider"></div>
                <div class="qr-wrap">
                    <div class="qr" aria-hidden="true">
                        <img src="<?php echo htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="QR code for payment address" loading="lazy" />
                    </div>
                    <div>
                        <div class="label">Deposit Address</div>
                        <div class="address"><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="note">Send exactly <?php echo $displayAmount; ?> to this address.</div>
                        <div class="note">Expires in: <span class="timer" id="timer">--:--</span> · Expires at <?php echo htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8'); ?> UTC</div>
                    </div>
                </div>

                <div class="actions">
                    <?php if ($testMode): ?>
                        <?php if (!$paid): ?>
                            <form method="post">
                                <button class="btn primary" type="submit">Done</button>
                            </form>
                        <?php endif; ?>
                        <a class="btn ghost" href="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">Refresh</a>
                    <?php endif; ?>
                    <button class="btn dark" type="button" onclick="copyValue('<?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?>')">Copy Address</button>
                    <button class="btn ghost" type="button" onclick="copyValue('<?php echo $displayAmount; ?>')">Copy Amount</button>
                </div>
                <div class="note">Transaction ID: <?php echo substr($txId, 0, 16); ?>…</div>
            </div>

            <div class="card">
                <div class="label">Payment Progress</div>
                <div class="timeline">
                    <div class="timeline-item">
                        <span class="timeline-dot active"></span>
                        <span>Payment created · Address generated for this order.</span>
                    </div>
                    <div class="timeline-item">
                        <span class="timeline-dot <?php echo $paid ? 'active' : ''; ?>"></span>
                        <span><?php echo $paid ? 'Payment confirmed' : 'Awaiting blockchain confirmations'; ?></span>
                    </div>
                    <div class="timeline-item">
                        <span class="timeline-dot <?php echo $paid ? 'active' : ''; ?>"></span>
                        <span><?php echo $paid ? 'Plan activated and receipt issued' : 'Plan activates after confirmation'; ?></span>
                    </div>
                </div>
                <div class="confirmations" id="confirmations" data-target="<?php echo $paid ? 3 : 0; ?>">
                    <div class="row"><span class="muted">Confirmations</span><span id="confirm-label">0/3</span></div>
                    <div class="confirm-bar"><div class="confirm-bar-fill" id="confirm-bar"></div></div>
                    <div class="note"><?php echo $paid ? 'Blockchain confirmed. Plan is active.' : 'Waiting for network confirmations.'; ?></div>
                </div>
                <div class="divider"></div>
                <div class="row"><span class="muted">Confirmations required</span><span>3</span></div>
                <div class="row"><span class="muted">Estimated arrival</span><span>~2–5 min</span></div>
                <div class="row"><span class="muted">Order status</span><span><?php echo htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8'); ?></span></div>
            </div>
        </div>

        <div>
            <div class="summary-card">
                <div class="label">Order Summary</div>
                <div class="value large"><?php echo $displayAmount; ?></div>
                <div class="note">Checkout for chat <?php echo (int)($order['chat_id'] ?? 0); ?></div>
                <div class="divider"></div>
                <div class="row"><span class="muted">Network fee (est.)</span><span>0.80 <?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="row"><span class="muted">Processing time</span><span>2–5 minutes</span></div>
                <div class="row"><span class="muted">Receipt</span><span><?php echo htmlspecialchars($receiptId, ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div class="divider"></div>
                <div class="notice">Only send <?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?> via <?php echo htmlspecialchars($network, ENT_QUOTES, 'UTF-8'); ?>. Sending other assets may result in permanent loss.</div>
            </div>
        </div>
    </div>
</div>
<script>
function copyValue(value) {
    if (!navigator.clipboard) {
        return;
    }
    navigator.clipboard.writeText(value);
}
(function () {
    var expiry = <?php echo $expiryTs ? (int)$expiryTs : 0; ?> * 1000;
    var timer = document.getElementById('timer');
    if (!timer || !expiry) return;
    function tick() {
        var diff = Math.max(0, expiry - Date.now());
        var m = Math.floor(diff / 60000);
        var s = Math.floor((diff % 60000) / 1000);
        timer.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }
    tick();
    setInterval(tick, 1000);
})();

(function () {
    var body = document.body;
    var toggle = document.getElementById('themeToggle');
    if (!toggle) return;
    var stored = localStorage.getItem('spnet_theme');
    if (stored === 'dark') {
        body.classList.add('dark');
    }
    function sync() {
        var isDark = body.classList.contains('dark');
        toggle.textContent = isDark ? 'Light mode' : 'Dark mode';
        toggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
    }
    toggle.addEventListener('click', function () {
        body.classList.toggle('dark');
        localStorage.setItem('spnet_theme', body.classList.contains('dark') ? 'dark' : 'light');
        sync();
    });
    sync();
})();

(function () {
    var confirmations = document.getElementById('confirmations');
    var label = document.getElementById('confirm-label');
    var bar = document.getElementById('confirm-bar');
    if (!confirmations || !label || !bar) return;
    var target = parseInt(confirmations.getAttribute('data-target') || '0', 10);
    var current = 0;
    label.textContent = current + '/3';
    bar.style.width = '0%';
    if (target <= 0) return;
    var interval = setInterval(function () {
        current += 1;
        if (current >= target) {
            current = target;
            clearInterval(interval);
        }
        label.textContent = current + '/3';
        bar.style.width = ((current / 3) * 100) + '%';
    }, 900);
})();

(function () {
    var sendBtn = document.getElementById('send-receipt');
    var emailInput = document.getElementById('receipt-email');
    var status = document.getElementById('receipt-status');
    if (!sendBtn || !emailInput || !status) return;
    sendBtn.addEventListener('click', function () {
        var email = (emailInput.value || '').trim();
        if (!email || email.indexOf('@') === -1) {
            status.textContent = 'Enter a valid email to receive the receipt.';
            return;
        }
        status.textContent = 'Receipt sent to ' + email + '.';
    });
})();
</script>
</body>
</html>
