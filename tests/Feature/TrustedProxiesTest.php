<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('test/request-origin', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]);
    }

    public function test_the_visitors_address_and_scheme_come_from_the_proxy(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
            ->get('test/request-origin')
            ->assertJson(['ip' => '203.0.113.7', 'secure' => true]);
    }

    public function test_a_forwarded_host_cannot_change_the_address_links_use(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeaders(['X-Forwarded-Host' => 'evil.example'])
            ->get('test/request-origin')
            ->assertJsonMissing(['host' => 'evil.example']);
    }
}
