<?php

namespace App\Providers;

use App\Application\Accounting\Contracts\AccountRepository;
use App\Application\Accounting\Contracts\JournalRepository;
use App\Application\Auth\Contracts\PasswordHasher;
use App\Application\Auth\Contracts\TokenRevocationRepository;
use App\Application\Auth\Contracts\TokenService;
use App\Application\Auth\Contracts\UserRepository;
use App\Application\Balances\Contracts\BalanceRepository;
use App\Application\Imports\Contracts\ImportFileStorage;
use App\Application\Imports\Contracts\ImportPreparationRepository;
use App\Application\Imports\Contracts\ImportRepository;
use App\Application\Outbox\Contracts\OutboxPublisher;
use App\Application\Outbox\Contracts\OutboxRepository;
use App\Application\Shared\Contracts\Clock;
use App\Infrastructure\Auth\Argon2idPasswordHasher;
use App\Infrastructure\Auth\JwtSettings;
use App\Infrastructure\Auth\LcobucciTokenService;
use App\Infrastructure\Auth\SystemClock;
use App\Infrastructure\Messaging\RedisOutboxPublisher;
use App\Infrastructure\Persistence\MysqlAccountRepository;
use App\Infrastructure\Persistence\MysqlBalanceRepository;
use App\Infrastructure\Persistence\MysqlImportPreparationRepository;
use App\Infrastructure\Persistence\MysqlImportRepository;
use App\Infrastructure\Persistence\MysqlJournalRepository;
use App\Infrastructure\Persistence\MysqlOutboxRepository;
use App\Infrastructure\Persistence\MysqlTokenRevocationRepository;
use App\Infrastructure\Persistence\MysqlUserRepository;
use App\Infrastructure\Storage\LocalImportFileStorage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ImportRepository::class, MysqlImportRepository::class);
        $this->app->bind(ImportPreparationRepository::class, MysqlImportPreparationRepository::class);
        $this->app->bind(ImportFileStorage::class, fn () => new LocalImportFileStorage((string) config('filesystems.disks.uploads.root')));
        $this->app->bind(OutboxRepository::class, MysqlOutboxRepository::class);
        $this->app->bind(OutboxPublisher::class, RedisOutboxPublisher::class);
        $this->app->bind(AccountRepository::class, MysqlAccountRepository::class);
        $this->app->bind(JournalRepository::class, MysqlJournalRepository::class);
        $this->app->bind(BalanceRepository::class, MysqlBalanceRepository::class);
        $this->app->bind(UserRepository::class, MysqlUserRepository::class);
        $this->app->bind(TokenRevocationRepository::class, MysqlTokenRevocationRepository::class);
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(PasswordHasher::class, fn () => new Argon2idPasswordHasher(Hash::driver('argon2id')));
        $this->app->singleton(JwtSettings::class, fn () => new JwtSettings(
            (string) config('jwt.secret'), (string) config('jwt.issuer'), (string) config('jwt.audience'),
        ));
        $this->app->singleton(TokenService::class, LcobucciTokenService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $identity = is_string($email) ? strtolower(trim($email)) : '';
            $response = fn (Request $request, array $headers) => response()->json([
                'message' => 'Too many login attempts. Please try again later.',
                'code' => 'rate_limited',
            ], 429, $headers);

            return [
                Limit::perMinute(30)->by('login-ip:'.$request->ip())->response($response),
                Limit::perMinute(5)->by('login-identity:'.hash('sha256', $identity.'|'.$request->ip()))->response($response),
            ];
        });
    }
}
