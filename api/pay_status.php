<?php
/**
 * GET /api/pay_status.php?checkout_id=...
 * Matches pollPaymentStatus() in plans.js.
 */

require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::get();
$checkoutId = $_GET['checkout_id'] ?? '';

$stmt = $db->prepare('SELECT * FROM payments WHERE checkout_request_id = :cr');
$stmt->execute(['cr' => $checkoutId]);
$payment = $stmt->fetch();

if (!$payment) {
    Http::json(['ok' => false, 'message' => 'Payment not found.'], 404);
}

if ($payment['status'] === 'pending') {
    Http::json(['ok' => true, 'pending' => true]);
}

if ($payment['status'] === 'failed') {
    Http::json(['ok' => false, 'message' => 'Payment was not completed. You can try again.']);
}

// Completed — hand back the voucher code the callback generated.
Http::json(['ok' => true, 'pending' => false, 'code' => $payment['voucher_code']]);
