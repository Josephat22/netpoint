<?php
/**
 * POST /api/pay.php
 * Body: { planId, phone, mac, ip }
 * Matches requestPayment() in plans.js.
 */

require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Mpesa.php';

$config = require __DIR__ . '/../config.php';
$db = Database::get();

$body = Http::jsonBody();
$planId = $body['planId'] ?? '';
$phone = $body['phone'] ?? '';
$mac = $body['mac'] ?? null;
$ip = $body['ip'] ?? Http::clientIp();

// NEVER trust a price from the client — look the plan up server-side.
$planStmt = $db->prepare('SELECT * FROM plans WHERE id = :id AND active = 1');
$planStmt->execute(['id' => $planId]);
$plan = $planStmt->fetch();

if (!$plan) {
    Http::json(['ok' => false, 'message' => 'That plan is not available.'], 404);
}

if (!preg_match('/^0?7\d{8}$/', preg_replace('/\s+/', '', $phone))) {
    Http::json(['ok' => false, 'message' => 'Enter a valid phone number.'], 422);
}

$insert = $db->prepare(
    'INSERT INTO payments (plan_id, phone, mac, ip, amount_kes, status)
     VALUES (:plan_id, :phone, :mac, :ip, :amount, "pending")'
);
$insert->execute([
    'plan_id' => $plan['id'],
    'phone'   => $phone,
    'mac'     => $mac,
    'ip'      => $ip,
    'amount'  => $plan['price_kes'],
]);
$paymentId = $db->lastInsertId();

try {
    $mpesa = new Mpesa($config['mpesa']);
    $result = $mpesa->stkPush($phone, (int) $plan['price_kes'], 'NETPOINT' . $paymentId);
} catch (Throwable $e) {
    error_log('M-Pesa STK push error: ' . $e->getMessage());
    $db->prepare('UPDATE payments SET status = "failed" WHERE id = :id')->execute(['id' => $paymentId]);
    Http::json(['ok' => false, 'message' => 'Could not start the payment. Try again.'], 502);
}

$db->prepare('UPDATE payments SET checkout_request_id = :cr WHERE id = :id')->execute([
    'cr' => $result['CheckoutRequestID'],
    'id' => $paymentId,
]);

Http::json(['ok' => true, 'checkoutId' => $result['CheckoutRequestID']]);
