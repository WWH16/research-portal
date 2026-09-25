<?php

namespace Tests\Feature\Settings;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MyProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_faculty_see_their_account_details(): void
    {
        $department = Department::create(['code' => 'CCSICT', 'name' => 'College of Computing Studies']);
        $user = User::factory()->create(['role' => 'faculty', 'department_id' => $department->id]);

        $this->actingAs($user);

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('My Profile')
            ->assertSee($user->researcher_id)
            ->assertSee('Faculty')
            ->assertSee('CCSICT')
            ->assertSee('To change these, contact the Research Office.');
    }

    public function test_sidebar_links_to_my_profile(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertSee(route('profile.edit'), escape: false)->assertSee('My Profile');
    }

    public function test_mobile_can_be_updated_and_cleared(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('mobile', ' 0917 123 4567 ')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('0917 123 4567', $user->fresh()->mobile);

        Livewire::test('pages::settings.profile')
            ->set('mobile', '')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertNull($user->fresh()->mobile);
    }

    public function test_role_and_department_cannot_be_changed_from_the_profile(): void
    {
        $user = User::factory()->create(['role' => 'faculty']);

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertFalse($user->fresh()->isAdmin());
        $this->assertNull($user->fresh()->department_id);
    }

    public function test_photo_can_be_uploaded_replaced_and_removed(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('photo', $this->png('me.png'))
            ->assertHasNoErrors();

        $first = $user->fresh()->profile_image;
        Storage::disk('public')->assertExists($first);
        $this->assertStringContainsString('/storage/profile-images/', $user->fresh()->profileImageUrl());

        Livewire::test('pages::settings.profile')
            ->set('photo', $this->png('me-again.png'))
            ->assertHasNoErrors();

        $second = $user->fresh()->profile_image;
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);

        Livewire::test('pages::settings.profile')->call('removePhoto');

        $this->assertNull($user->fresh()->profile_image);
        $this->assertNull($user->fresh()->profileImageUrl());
        Storage::disk('public')->assertMissing($second);
    }

    public function test_photo_must_be_an_image_under_two_megabytes(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('photo', UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'))
            ->assertHasErrors(['photo']);

        Livewire::test('pages::settings.profile')
            ->set('photo', $this->png('huge.png')->size(3000))
            ->assertHasErrors(['photo' => 'max']);

        $this->assertNull($user->fresh()->profile_image);
    }

    /**
     * A real 1x1 PNG built from bytes, so the test does not need the GD extension.
     */
    private function png(string $name): File
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='),
        );
    }
}
