<?php

require_once __DIR__ . '/Database.php';

class RateLimiter
{
    /**
     * True if this IP or MAC has made too many attempts recently.
     * Checked BEFORE looking the code up, so brute-forcing costs the
     * attacker real wall-clock time regardless of whether codes are valid.
     */
    public static function isBlocked(string $ip, ?string $mac, array $config): bool
    {
        $db = Database::get();
        $windowStart = date('Y-m-d H:i:s', time() - $config['window_minutes'] * 60);

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM redemption_attempts
             WHERE created_at >= :window_start
             AND success = 0
             AND (ip = :ip OR (mac IS NOT NULL AND mac = :mac))'
        );
        $stmt->execute([
            'window_start' => $windowStart,
            'ip'           => $ip,
            'mac'          => $mac,
        ]);

        return (int) $stmt->fetchColumn() >= $config['max_attempts_per_window'];
    }

    public static function recordAttempt(string $ip, ?string $mac, string $code, bool $success): void
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO redemption_attempts (mac, ip, code_tried, success)
             VALUES (:mac, :ip, :code, :success)'
        );
        $stmt->execute([
            'mac'     => $mac,
            'ip'      => $ip,
            'code'    => $code,
            'success' => $success ? 1 : 0,
        ]);
    }
}
