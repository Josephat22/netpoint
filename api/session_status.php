<?php
/**
 * GET /api/session_status.php?mac=...
 * Matches fetchSessionStatus() in script.js.
 */

require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/RouterOsApi.php';

$config = require __DIR__ . '/../config.php';
$db = Database::get();

$mac = $_GET['mac'] ?? '';
if ($mac === '') {
    Http::json(['ok' => false, 'message' => 'No device identified.'], 400);
}

$stmt = $db->prepare(
    'SELECT v.*, p.data_limit_mb, p.type
     FROM vouchers v JOIN plans p ON p.id = v.plan_id
     WHERE v.mac = :mac AND v.status = "active"
     ORDER BY v.activated_at DESC LIMIT 1'
);
$stmt->execute(['mac' => $mac]);
$voucher = $stmt->fetch();

if (!$voucher) {
    Http::json(['ok' => false, 'message' => 'No active session for this device.'], 404);
}

$minutesRemaining = max(0, (int) ((strtotime($voucher['expires_at']) - time()) / 60));

$dataUsedMB = 0;
try {
    $router = new RouterOsApi();
    $router->connect($config['router']['host'], $config['router']['port']);
    $router->login($config['router']['user'], $config['router']['password']);
    $active = $router->getActiveSessionByMac($mac);
    $router->close();

    if ($active) {
        // bytes-in/out are strings of raw bytes from the router; convert to MB.
        $bytesTotal = (int) ($active['bytes-in'] ?? 0) + (int) ($active['bytes-out'] ?? 0);
        $dataUsedMB = round($bytesTotal / (1024 * 1024));
    }
} catch (Throwable $e) {
    error_log('RouterOS error during session status check: ' . $e->getMessage());
    // Fall through with dataUsedMB = 0 rather than failing the whole request —
    // time-remaining is still useful even if the router is briefly unreachable.
}

$planSpeed = $config['router']['profile_map'][$voucher['plan_id']] ?? 'Standard';

Http::json([
    'ok'               => true,
    'dataUsedMB'       => $dataUsedMB,
    'dataLimitMB'      => $voucher['data_limit_mb'] ?? 999999, // effectively unlimited
    'minutesRemaining' => $minutesRemaining,
    'planSpeed'        => $planSpeed,
]);
