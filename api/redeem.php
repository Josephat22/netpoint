<?php
/**
 * POST /api/redeem.php
 * Body: { code, mac, ip, hotspot }
 * Matches redeemVoucher() in script.js.
 */

require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/RateLimiter.php';
require_once __DIR__ . '/../lib/RouterOsApi.php';

$config = require __DIR__ . '/../config.php';
$db = Database::get();

$body = Http::jsonBody();
$code = strtoupper(trim($body['code'] ?? ''));
$mac = $body['mac'] ?? null;
$ip = Http::clientIp();

if ($code === '') {
    Http::json(['ok' => false, 'message' => 'Enter a voucher code first.']);
}

// Rate limit BEFORE touching the database record, so guessing costs time
// regardless of whether the code turns out to be valid.
if (RateLimiter::isBlocked($ip, $mac, $config['rate_limit'])) {
    Http::json(['ok' => false, 'message' => 'Too many attempts. Please wait a few minutes and try again.'], 429);
}

$stmt = $db->prepare('SELECT * FROM vouchers WHERE code = :code');
$stmt->execute(['code' => $code]);
$voucher = $stmt->fetch();

if (!$voucher) {
    RateLimiter::recordAttempt($ip, $mac, $code, false);
    Http::json(['ok' => false, 'message' => 'That code was not recognized. Check it and try again.']);
}

if ($voucher['status'] === 'expired') {
    RateLimiter::recordAttempt($ip, $mac, $code, false);
    Http::json(['ok' => false, 'message' => 'This voucher has already expired.']);
}

if ($voucher['status'] === 'active') {
    if ($voucher['mac'] !== $mac) {
        // Same code, different device — this is exactly what MAC binding is for.
        RateLimiter::recordAttempt($ip, $mac, $code, false);
        Http::json(['ok' => false, 'message' => 'This voucher is already active on another device.']);
    }
    // Same device reconnecting on an already-active voucher — let it through.
    RateLimiter::recordAttempt($ip, $mac, $code, true);
    Http::json(['ok' => true, 'timeLeft' => timeLeftLabel($voucher['expires_at'])]);
}

// First-time redemption: bind to this MAC, activate, and create the router login.
$planStmt = $db->prepare('SELECT * FROM plans WHERE id = :id');
$planStmt->execute(['id' => $voucher['plan_id']]);
$plan = $planStmt->fetch();

$expiresAt = date('Y-m-d H:i:s', time() + $plan['duration_min'] * 60);

try {
    $router = new RouterOsApi();
    $router->connect($config['router']['host'], $config['router']['port']);
    $router->login($config['router']['user'], $config['router']['password']);
    $router->addHotspotUser(
        $code,
        $code,
        $config['router']['profile_map'][$plan['id']],
        $mac
    );
    $router->close();
} catch (Throwable $e) {
    // Don't activate the voucher in our own records if the router never
    // actually got the user created — that would sell access we can't deliver.
    error_log('RouterOS error during redeem: ' . $e->getMessage());
    Http::json(['ok' => false, 'message' => 'Could not connect you right now. Please try again shortly.'], 502);
}

$update = $db->prepare(
    'UPDATE vouchers SET status = "active", mac = :mac, ip = :ip, activated_at = NOW(), expires_at = :expires
     WHERE code = :code'
);
$update->execute(['mac' => $mac, 'ip' => $ip, 'expires' => $expiresAt, 'code' => $code]);

RateLimiter::recordAttempt($ip, $mac, $code, true);
Http::json(['ok' => true, 'timeLeft' => timeLeftLabel($expiresAt)]);

function timeLeftLabel(string $expiresAt): string
{
    $minutes = max(0, (int) ((strtotime($expiresAt) - time()) / 60));
    if ($minutes >= 60) {
        return round($minutes / 60, 1) . ' hours';
    }
    return $minutes . ' minutes';
}
