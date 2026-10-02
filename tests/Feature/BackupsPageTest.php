<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_warns_when_uploads_are_on_the_server_disk(): void
    {
        $this->app['env'] = 'production';
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('backups.index'))
            ->assertSee('Uploaded files are on the server disk')
            ->assertSee('Project documents: Server disk');
    }

    public function test_no_warning_once_cloud_buckets_are_attached(): void
    {
        $this->app['env'] = 'production';
        config(['filesystems.disks.submissions.driver' => 's3', 'filesystems.disks.public.driver' => 's3']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('backups.index'))
            ->assertDontSee('Uploaded files are on the server disk')
            ->assertSee('Project documents: Cloud bucket');
    }
}
