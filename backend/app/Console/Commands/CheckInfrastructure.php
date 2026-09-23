<?php

namespace App\Console\Commands;

use App\Infrastructure\Diagnostics\ProbeSharedUploadJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class CheckInfrastructure extends Command
{
    protected $signature = 'app:check-infrastructure {--timeout=20 : Maximum seconds to wait for the worker}';

    protected $description = 'Check MySQL, Redis, queue delivery and the private shared upload volume';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This development diagnostic is restricted to local/testing environments.');

            return self::FAILURE;
        }

        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 60],
        ]);

        if ($timeout === false) {
            $this->error('The timeout must be an integer between 1 and 60 seconds.');

            return self::FAILURE;
        }

        $probeId = bin2hex(random_bytes(16));
        $file = 'diagnostics/'.$probeId.'.txt';
        $cacheKey = 'diagnostics:'.$probeId;
        $fileCreated = false;
        $redisReady = false;

        try {
            if (config('queue.default') !== 'redis' || config('database.default') !== 'mysql') {
                throw new RuntimeException('This diagnostic requires the configured MySQL database and Redis queue.');
            }

            DB::select('SELECT 1 AS ready');
            $this->info('MySQL connection: OK');
            Redis::connection()->ping();
            $redisReady = true;
            $this->info('Redis connection: OK');

            $payload = random_bytes(64);
            Storage::disk('uploads')->put($file, $payload);
            $fileCreated = true;
            ProbeSharedUploadJob::dispatch($probeId)->onConnection('redis')->onQueue('default');
            $deadline = microtime(true) + $timeout;

            do {
                if (Cache::store('redis')->get($cacheKey) === hash('sha256', $payload)) {
                    $this->info('Redis job processed by worker; private upload bytes match: OK');

                    return self::SUCCESS;
                }

                usleep(100000);
            } while (microtime(true) < $deadline);

            throw new RuntimeException('Worker did not confirm the shared upload. Check the worker logs and volume permissions.');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($fileCreated) {
                Storage::disk('uploads')->delete($file);
            }

            if ($redisReady) {
                Cache::store('redis')->forget($cacheKey);
            }
        }
    }
}
