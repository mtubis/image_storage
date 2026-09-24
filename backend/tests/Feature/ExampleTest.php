<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The app has no web UI (see CLAUDE.md); the health check is the only
     * route this skeleton actually serves before Phase 1 adds the API.
     */
    public function test_the_health_check_endpoint_returns_a_successful_response(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }
}
