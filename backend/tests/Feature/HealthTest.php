<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_api_boots_and_returns_its_operational_contract(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'service' => 'finance-ledger-api']);
    }

    public function test_allowed_frontend_origin_can_read_api_responses(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/v1/health')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_unlisted_origin_is_not_granted_cors_access(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $response = $this->withHeader('Origin', 'https://unlisted.example')
            ->getJson('/api/v1/health');

        // A fixed allowed origin is also valid: browsers reject the mismatch.
        self::assertNotContains(
            $response->headers->get('Access-Control-Allow-Origin'),
            ['*', 'https://unlisted.example'],
        );
    }

    public function test_bearer_header_is_allowed_by_preflight(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/v1/health')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_unknown_api_routes_return_json_even_without_accept_header(): void
    {
        $this->get('/api/v1/unknown')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
