<?php

/**
 * Minimal RouterOS API client.
 *
 * Implements MikroTik's binary API protocol directly over a TCP socket —
 * no Composer package needed, which matters on shared hosting where you
 * often can't install one. Written against the plain-text login supported
 * since RouterOS 6.43+: the client sends the username/password straight
 * away instead of doing the older MD5 challenge-response dance.
 *
 * If you're running RouterOS older than 6.43, upgrade the router (it's a
 * free update) rather than adding the legacy login method here — you want
 * a current version for security reasons anyway.
 */
class RouterOsApi
{
    private $socket;

    public function connect(string $host, int $port = 8728, int $timeoutSeconds = 5): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);

        if ($this->socket === false) {
            throw new RuntimeException("Could not reach router at $host:$port ($errstr)");
        }

        stream_set_timeout($this->socket, $timeoutSeconds);
    }

    public function login(string $username, string $password): void
    {
        $this->write('/login', [
            'name'     => $username,
            'password' => $password,
        ]);

        $reply = $this->readSentence();

        if ($reply['status'] !== '!done') {
            throw new RuntimeException('RouterOS login failed — check credentials and that the API service is enabled.');
        }
    }

    /**
     * Send a command and return every row RouterOS replies with.
     * Example: $api->talk('/ip/hotspot/user/print', ['?name' => 'ABC123']);
     */
    public function talk(string $command, array $params = []): array
    {
        $this->write($command, $params);
        $rows = [];

        while (true) {
            $reply = $this->readSentence();
            if (!empty($reply['attrs'])) {
                $rows[] = $reply['attrs'];
            }
            if ($reply['status'] === '!done') {
                break;
            }
            if ($reply['status'] === '!trap') {
                $message = $reply['attrs']['message'] ?? 'unknown RouterOS error';
                throw new RuntimeException("RouterOS error on $command: $message");
            }
        }

        return $rows;
    }

    public function close(): void
    {
        if ($this->socket) {
            fclose($this->socket);
        }
    }

    // ---- Higher-level helpers for what this project actually needs -------

    /** Creates (or re-enables) a hotspot login, optionally locked to one MAC. */
    public function addHotspotUser(string $name, string $password, string $profile, ?string $macAddress = null): void
    {
        $params = [
            'name'     => $name,
            'password' => $password,
            'profile'  => $profile,
        ];
        if ($macAddress) {
            $params['mac-address'] = $macAddress;
        }
        $this->talk('/ip/hotspot/user/add', $params);
    }

    /** Looks up the active session for a device, if it's currently connected. */
    public function getActiveSessionByMac(string $macAddress): ?array
    {
        $rows = $this->talk('/ip/hotspot/active/print', ['?mac-address' => $macAddress]);
        return $rows[0] ?? null;
        // Useful fields on the returned row: 'bytes-in', 'bytes-out', 'uptime'.
    }

    // ---- Protocol-level read/write (the length-prefixed word encoding) ---

    private function write(string $command, array $params = []): void
    {
        $this->writeWord($command);
        foreach ($params as $key => $value) {
            $prefix = str_starts_with($key, '?') ? '' : '=';
            $this->writeWord($prefix . $key . '=' . $value);
        }
        $this->writeWord(''); // empty word terminates the sentence
    }

    private function writeWord(string $word): void
    {
        fwrite($this->socket, $this->encodeLength(strlen($word)) . $word);
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            $length |= 0x8000;
            return chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x200000) {
            $length |= 0xC00000;
            return chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x10000000) {
            $length |= 0xE0000000;
            return chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF)
                 . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        return chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF)
             . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
    }

    /** Reads one full sentence (a series of words up to the terminating empty word). */
    private function readSentence(): array
    {
        $status = '';
        $attrs = [];

        while (true) {
            $word = $this->readWord();
            if ($word === '') {
                break; // end of sentence
            }
            if (str_starts_with($word, '!')) {
                $status = $word;
            } elseif (str_starts_with($word, '=')) {
                $body = substr($word, 1);
                [$key, $value] = array_pad(explode('=', $body, 2), 2, '');
                $attrs[$key] = $value;
            }
        }

        return ['status' => $status, 'attrs' => $attrs];
    }

    private function readWord(): string
    {
        $length = $this->decodeLength();
        return $length > 0 ? $this->readBytes($length) : '';
    }

    private function decodeLength(): int
    {
        $c = ord($this->readBytes(1));

        if (($c & 0x80) === 0x00) {
            return $c;
        }
        if (($c & 0xC0) === 0x80) {
            return (($c & 0x3F) << 8) + ord($this->readBytes(1));
        }
        if (($c & 0xE0) === 0xC0) {
            return (($c & 0x1F) << 16) + (ord($this->readBytes(1)) << 8) + ord($this->readBytes(1));
        }
        if (($c & 0xF0) === 0xE0) {
            return (($c & 0x0F) << 24) + (ord($this->readBytes(1)) << 16)
                 + (ord($this->readBytes(1)) << 8) + ord($this->readBytes(1));
        }
        // c === 0xF0: full 4-byte length follows, marker byte carries no bits.
        $bytes = $this->readBytes(4);
        return (ord($bytes[0]) << 24) + (ord($bytes[1]) << 16) + (ord($bytes[2]) << 8) + ord($bytes[3]);
    }

    private function readBytes(int $count): string
    {
        $data = '';
        while (strlen($data) < $count) {
            $chunk = fread($this->socket, $count - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Lost connection to router while reading.');
            }
            $data .= $chunk;
        }
        return $data;
    }
}
