<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Auth\Contracts\TokenService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MysqlDatabase;

final class TokenRevocationDurabilityTest extends TestCase
{
    use MakesHttpRequests;

    protected Application $app;

    public function test_logout_survives_a_new_database_connection_and_empty_cache(): void
    {
        // No outer transaction: this test specifically verifies committed persistence across connections.
        $this->app = MysqlDatabase::application();
        $userId = null;
        try {
            $userId = DB::table('users')->insertGetId([
                'name' => 'Durability', 'email' => 'durability@example.test', 'password' => 'unused',
            ]);
            $token = $this->app->make(TokenService::class)->issue((string) $userId)->accessToken;
            $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
            DB::purge();
            Cache::flush(); // The isolated array cache configured by phpunit.integration.xml.
            self::assertSame(1, DB::table('revoked_tokens')->where('user_id', $userId)->count());
            $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        } finally {
            if ($userId !== null) {
                DB::table('revoked_tokens')->where('user_id', $userId)->delete();
                DB::table('users')->where('id', $userId)->delete();
            }
            DB::disconnect();
            $this->app->flush();
            HandleExceptions::flushState($this);
        }
    }
}
