<?php

declare(strict_types=1);

namespace Tests\Performance;

use RuntimeException;

/** Small streaming client for the local benchmark PHP HTTP server. Never logs tokens. */
final class HttpClient
{
    private string $token = '';

    private float $authenticatedAt = 0;

    public string $ownerId = '';

    public function __construct(private readonly int $port) {}

    public function login(): void
    {
        $response = $this->send('POST', '/auth/login', json_encode([
            'email' => getenv('DEMO_USER_EMAIL'), 'password' => getenv('DEMO_USER_PASSWORD'),
        ], JSON_THROW_ON_ERROR), expected: 200);
        $this->token = $response['data']['access_token'];
        $this->ownerId = $response['data']['user']['id'];
        $this->authenticatedAt = microtime(true);
    }

    /** @return array<string,mixed> */
    public function get(string $path, int $expected = 200): array
    {
        $this->renew();

        return $this->send('GET', $path, expected: $expected);
    }

    /** @return array<string,mixed> */
    public function upload(string $path, int $expected = 202): array
    {
        $this->renew();

        return $this->send('POST', '/imports', file: $path, expected: $expected);
    }

    private function renew(): void
    {
        if (microtime(true) - $this->authenticatedAt > 780) {
            $this->login();
        }
    }

    /** @return array<string,mixed> */
    private function send(string $method, string $path, string $body = '', ?string $file = null, int $expected = 200): array
    {
        $boundary = 'performance-'.bin2hex(random_bytes(12));
        $prefix = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"synthetic.csv\"\r\nContent-Type: text/csv\r\n\r\n";
        $suffix = "\r\n--{$boundary}--\r\n";
        $length = $file === null ? strlen($body) : strlen($prefix) + filesize($file) + strlen($suffix);
        $type = $file === null ? 'application/json' : 'multipart/form-data; boundary='.$boundary;
        $socket = stream_socket_client('tcp://127.0.0.1:'.$this->port, $errno, $error, 10);
        if ($socket === false) {
            throw new RuntimeException('HTTP server unavailable.');
        }
        stream_set_timeout($socket, 180);
        try {
            $headers = "$method /api/v1$path HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\nAccept: application/json\r\nConnection: close\r\nContent-Type: $type\r\nContent-Length: $length\r\n";
            if ($this->token !== '') {
                $headers .= 'Authorization: Bearer '.$this->token."\r\n";
            }
            self::write($socket, $headers."\r\n");
            if ($file === null) {
                self::write($socket, $body);
            } else {
                self::write($socket, $prefix);
                $stream = fopen($file, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('Cannot read upload.');
                }
                try {
                    while (! feof($stream)) {
                        self::write($socket, fread($stream, 65536));
                    }
                } finally {
                    fclose($stream);
                }
                self::write($socket, $suffix);
            }
            $response = stream_get_contents($socket);
            [$headers, $json] = explode("\r\n\r\n", $response, 2);
            if (preg_match('/\AHTTP\/1\.[01] ([0-9]{3})/', $headers, $matches) !== 1 || (int) $matches[1] !== $expected) {
                throw new RuntimeException('Unexpected HTTP response for '.$path.': '.substr($response, 0, 1000));
            }

            return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private static function write($socket, string $bytes): void
    {
        for ($offset = 0; $offset < strlen($bytes);) {
            $written = fwrite($socket, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('HTTP socket closed during request.');
            }
            $offset += $written;
        }
    }
}
