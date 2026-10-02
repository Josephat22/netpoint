<?php
/**
 * POST /api/pay_callback.php
 * Safaricom calls this URL directly (server-to-server) once the customer
 * enters their M-Pesa PIN or cancels/times out. This is where a voucher
 * code actually gets created — not in pay.php, since we don't know the
 * payment succeeded until this fires.
 *
 * Configure this exact URL as callback_url in config.php, and make sure
 * it's reachable from the public internet (Safaricom can't reach an
 * internal-only address).
 */

require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Database.php';

$db = Database::get();
$payload = Http::jsonBody();

$callback = $payload['Body']['stkCallback'] ?? null;
if (!$callback) {
    // Not a shape we recognize — acknowledge anyway so Safaricom stops retrying,
    // but log it so you notice if their payload format ever changes.
    error_log('Unrecognized M-Pesa callback payload: ' . json_encode($payload));
    Http::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}

$checkoutRequestId = $callback['CheckoutRequestID'];
$resultCode = $callback['ResultCode'];

$stmt = $db->prepare('SELECT * FROM payments WHERE checkout_request_id = :cr');
$stmt->execute(['cr' => $checkoutRequestId]);
$payment = $stmt->fetch();

if (!$payment) {
    error_log("Callback for unknown CheckoutRequestID: $checkoutRequestId");
    Http::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}

if ((int) $resultCode !== 0) {
    // 1032 = user cancelled, 1037 = timeout, etc. Just mark it failed either way.
    $db->prepare('UPDATE payments SET status = "failed" WHERE id = :id')
       ->execute(['id' => $payment['id']]);
    Http::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}

// Success. Pull the M-Pesa receipt number out of the metadata array.
$receipt = null;
foreach ($callback['CallbackMetadata']['Item'] ?? [] as $item) {
    if ($item['Name'] === 'MpesaReceiptNumber') {
        $receipt = $item['Value'];
    }
}

$voucherCode = generateVoucherCode($db);

$db->beginTransaction();
try {
    $db->prepare(
        'INSERT INTO vouchers (code, plan_id, status, payment_id) VALUES (:code, :plan_id, "unused", :payment_id)'
    )->execute([
        'code'       => $voucherCode,
        'plan_id'    => $payment['plan_id'],
        'payment_id' => $payment['id'],
    ]);

    $db->prepare(
        'UPDATE payments SET status = "completed", mpesa_receipt = :receipt, voucher_code = :code WHERE id = :id'
    )->execute([
        'receipt' => $receipt,
        'code'    => $voucherCode,
        'id'      => $payment['id'],
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('Failed to save voucher after successful payment: ' . $e->getMessage());
}

// BACKEND note: if you'd rather the voucher auto-activate immediately
// (rather than waiting for the user to submit it on the Connect tab), call
// the same RouterOsApi::addHotspotUser() logic from redeem.php here too,
// using $payment['mac']. Left as a manual redeem step for now since it
// mirrors the current front-end flow (poll for the code, then it's typed
// into the voucher field automatically by pay_status.php's response).

Http::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

function generateVoucherCode(PDO $db): string
{
    $charset = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/l
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $charset[random_int(0, strlen($charset) - 1)];
        }
        $stmt = $db->prepare('SELECT 1 FROM vouchers WHERE code = :code');
        $stmt->execute(['code' => $code]);
    } while ($stmt->fetch()); // extremely unlikely, but check for collisions anyway

    return $code;
}
