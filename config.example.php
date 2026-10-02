<?php
/**
 * NetPoint backend configuration.
 *
 * This is the ONLY file you should need to edit to deploy this for a new
 * hotspot site. Everything else reads its settings from here.
 *
 * IMPORTANT: this file holds real secrets once filled in (DB password,
 * M-Pesa consumer secret, router password). Make sure it is NOT web-
 * accessible — the included .htaccess already blocks direct requests to
 * it, but double check that on your host too.
 */

return [

    // ---- Database -------------------------------------------------------
    'db' => [
        'host'     => '127.0.0.1',
        'name'     => 'netpoint',
        'user'     => 'netpoint_user',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    // ---- Support contact (mirrors SUPPORT_PHONE in script.js) -----------
    'support_phone' => '254700000000',

    // ---- M-Pesa Daraja (Safaricom) ---------------------------------------
    // Get these from https://developer.safaricom.co.ke after creating an app.
    // Use the sandbox values while testing, then switch base_url to
    // 'https://api.safaricom.co.ke' and use your production shortcode/
    // passkey when you go live.
    'mpesa' => [
        'base_url'           => 'https://sandbox.safaricom.co.ke',
        'consumer_key'       => 'CHANGE_ME',
        'consumer_secret'    => 'CHANGE_ME',
        'shortcode'          => '174379',        // Paybill/till number
        'passkey'            => 'CHANGE_ME',     // Lipa Na M-Pesa Online passkey
        'callback_url'       => 'https://yourdomain.com/api/pay_callback.php',
        'transaction_desc'   => 'NetPoint WiFi voucher',
    ],

    // ---- MikroTik RouterOS API --------------------------------------------
    // The router must have the API service enabled: IP > Services > api
    // (port 8728), or api-ssl (port 8729) if you want it encrypted.
    'router' => [
        'host'     => '10.10.9.1',
        'port'     => 8728,
        'user'     => 'netpoint-api',
        'password' => 'CHANGE_ME',
        // Maps our plan IDs to the hotspot user profile names you've
        // already configured on the router (Profile names carry the
        // actual data cap / rate-limit enforcement).
        'profile_map' => [
            'p1' => 'plan-500mb',
            'p2' => 'plan-1gb',
            'p3' => 'plan-2gb',
            'p4' => 'plan-5gb',
            'p5' => 'plan-10gb',
            'p6' => 'plan-unlimited-12h',
            'p7' => 'plan-unlimited-24h',
            'p8' => 'plan-unlimited-7d',
        ],
    ],

    // ---- Rate limiting ----------------------------------------------------
    'rate_limit' => [
        'max_attempts_per_window' => 5,
        'window_minutes'          => 15,
    ],
];
