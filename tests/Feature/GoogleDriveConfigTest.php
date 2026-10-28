<?php

namespace Tests\Feature;

use Laravel\Socialite\Facades\Socialite;
use Masbug\Flysystem\GoogleDriveAdapter;
use Tests\TestCase;

class GoogleDriveConfigTest extends TestCase
{
    public function test_google_settings_are_read_from_the_environment(): void
    {
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            $this->assertArrayHasKey($key, config('services.google'));
        }

        $this->assertTrue(class_exists(Socialite::class));
        $this->assertTrue(class_exists(GoogleDriveAdapter::class));
    }
}
