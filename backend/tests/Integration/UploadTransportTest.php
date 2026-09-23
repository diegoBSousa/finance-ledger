<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Auth\Contracts\TokenService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Integration\Support\UsesCommittedMysql;
use Tests\Support\TemporaryUploads;

final class UploadTransportTest extends TestCase
{
    use TemporaryUploads;
    use UsesCommittedMysql { tearDown as private cleanupDatabase; }

    private ?Process $server = null;

    private int $port;

    private string $token;

    protected function tearDown(): void
    {
        $this->server?->stop(0);
        $this->removeUploadRoot();
        $this->cleanupDatabase();
    }

    private function prepare(): void
    {
        $this->createUploadRoot();
        $this->createUser();
        $this->commitFixtures();
        $this->token = $this->app->make(TokenService::class)->issue('7')->accessToken;
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket);
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $env = ['IMPORT_UPLOAD_ROOT' => $this->uploadRoot, 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'JWT_SECRET' => config('jwt.secret'), 'APP_KEY' => config('app.key')];
        $this->server = new Process([PHP_BINARY, '-d', 'enable_post_data_reading=0', '-d', 'post_max_size=110000000', '-d', 'upload_max_filesize=100000000',
            '-d', 'max_file_uploads=1', '-d', 'memory_limit=64M', '-S', '127.0.0.1:'.$this->port, '-t', 'public'], base_path(), $env, timeout: 60);
        $this->server->start();
        $deadline = microtime(true) + 5;
        do {
            $probe = @stream_socket_client('tcp://127.0.0.1:'.$this->port, $errno, $error, 0.1);
            if ($probe !== false) {
                fclose($probe);

                return;
            }usleep(10000);
        } while ($this->server->isRunning() && microtime(true) < $deadline);
        self::fail('PHP HTTP server did not start: '.$this->server->getErrorOutput());
    }

    /** @param list<string> $fields @return array{int,array<string,mixed>} */
    private function upload(string $source, array $fields = ['file'], bool $authenticated = true): array
    {
        $boundary = 'ledger-boundary-'.bin2hex(random_bytes(8));
        $prefixes = [];
        $length = 0;
        foreach ($fields as $field) {
            $prefix = "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"file.csv\"\r\nContent-Type: text/csv\r\n\r\n";
            $prefixes[] = $prefix;
            $length += strlen($prefix) + filesize($source) + 2;
        }
        $suffix = "--{$boundary}--\r\n";
        $length += strlen($suffix);
        $socket = stream_socket_client('tcp://127.0.0.1:'.$this->port, $errno, $error, 5);
        self::assertIsResource($socket);
        stream_set_timeout($socket, 30);
        $headers = "POST /api/v1/imports HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\nAccept: application/json\r\nConnection: close\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: {$length}\r\n";
        if ($authenticated) {
            $headers .= 'Authorization: Bearer '.$this->token."\r\n";
        }
        $this->write($socket, $headers."\r\n");
        foreach ($prefixes as $prefix) {
            $this->write($socket, $prefix);
            $file = fopen($source, 'rb');
            while (! feof($file)) {
                $this->write($socket, fread($file, 65536));
            }fclose($file);
            $this->write($socket, "\r\n");
        }
        $this->write($socket, $suffix);
        $response = stream_get_contents($socket);
        fclose($socket);
        self::assertIsString($response);
        self::assertStringContainsString("\r\n\r\n", $response, $this->server->getErrorOutput());
        [$headers,$body] = explode("\r\n\r\n", $response, 2);
        preg_match('/HTTP\/1\.[01] ([0-9]{3})/', $headers, $match);
        self::assertArrayHasKey(1, $match, $headers);
        self::assertStringContainsString('application/json', $headers, $this->server->getErrorOutput());

        return [(int) $match[1], json_decode($body, true, flags: JSON_THROW_ON_ERROR)];
    }

    /** @param resource $socket */
    private function write($socket, string $bytes): void
    {
        for ($offset = 0; $offset < strlen($bytes);) {
            $written = fwrite($socket, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                self::fail('HTTP upload socket closed.');
            }$offset += $written;
        }
    }

    public function test_native_multipart_is_accepted_with_file_body_parsing_deferred(): void
    {
        $this->prepare();
        [$status,$body] = $this->upload($this->sourceFile());
        self::assertSame(202, $status, json_encode($body));
        self::assertSame('pending', $body['data']['status']);
        self::assertSame(1, DB::table('imports')->count());
        self::assertSame(1, DB::table('outbox_deliveries')->count());
    }

    #[DataProvider('extraFields')]
    public function test_duplicate_or_extra_files_are_rejected_instead_of_silently_discarded(array $fields): void
    {
        $this->prepare();
        [$status,$body] = $this->upload($this->sourceFile(), $fields);
        self::assertSame(422, $status, json_encode($body));
        self::assertSame(0, DB::table('imports')->count());
        self::assertSame(0, DB::table('outbox_events')->count());
    }

    public static function extraFields(): iterable
    {
        yield [['file', 'file']];
        yield [['file', 'other']];
        yield [['file[]']];
    }

    #[DataProvider('sizes')]
    public function test_real_http_enforces_decimal_file_and_request_size_limits_with_64mb_php_memory(int $size, int $expected): void
    {
        $this->prepare();
        $path = $this->sourceFile();
        $file = fopen($path, 'r+b');
        ftruncate($file, $size);
        fclose($file);
        clearstatcache(true, $path);
        [$status,$body] = $this->upload($path);
        self::assertSame($expected, $status, json_encode($body));
        self::assertSame($expected === 202 ? 1 : 0, DB::table('imports')->count());
        if ($expected === 413) {
            self::assertSame('upload_too_large', $body['code']);
        } else {
            self::assertSame(100000000, $body['data']['file_size_bytes']);
        }
    }

    public static function sizes(): iterable
    {
        yield [100000000, 202];
        yield [100000001, 413];
        yield [110000001, 413];
    }
}
