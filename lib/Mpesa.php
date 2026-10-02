<?php

/**
 * Safaricom Daraja API client — OAuth token + STK Push (Lipa Na M-Pesa Online).
 * Plain cURL, no external library needed.
 */
class Mpesa
{
    private array $config;

    public function __construct(array $mpesaConfig)
    {
        $this->config = $mpesaConfig;
    }

    /** Fetches a short-lived OAuth access token used to authorize the STK push call. */
    public function getAccessToken(): string
    {
        $url = $this->config['base_url'] . '/oauth/v1/generate?grant_type=client_credentials';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->config['consumer_key'] . ':' . $this->config['consumer_secret'],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Could not reach Safaricom's OAuth endpoint: $error");
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('Safaricom did not return an access token: ' . $response);
        }

        return $data['access_token'];
    }

    /**
     * Sends an STK Push prompt to the customer's phone.
     * Returns Safaricom's response, which includes CheckoutRequestID —
     * save that, since it's how you'll match up the callback later.
     */
    public function stkPush(string $phone, int $amount, string $accountReference): array
    {
        $token = $this->getAccessToken();
        $timestamp = date('YmdHis');
        $password = base64_encode($this->config['shortcode'] . $this->config['passkey'] . $timestamp);

        $payload = [
            'BusinessShortCode' => $this->config['shortcode'],
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => $amount,
            'PartyA'            => $this->normalizePhone($phone),
            'PartyB'            => $this->config['shortcode'],
            'PhoneNumber'       => $this->normalizePhone($phone),
            'CallBackURL'       => $this->config['callback_url'],
            'AccountReference'  => $accountReference,
            'TransactionDesc'   => $this->config['transaction_desc'],
        ];

        $ch = curl_init($this->config['base_url'] . '/mpesa/stkpush/v1/processrequest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer $token",
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("STK push request failed: $error");
        }

        $data = json_decode($response, true);
        if (empty($data['CheckoutRequestID'])) {
            $message = $data['errorMessage'] ?? $response;
            throw new RuntimeException("STK push rejected: $message");
        }

        return $data;
    }

    /** Safaricom wants 2547XXXXXXXX, not 07XXXXXXXX or +2547XXXXXXXX. */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '0')) {
            $digits = '254' . substr($digits, 1);
        }
        return $digits;
    }
}
