<?php

namespace Tests\Feature\Settings;

use App\Models\Department;
use App\Models\GoogleDriveConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_photo_is_kept_on_google_drive_replaced_and_removed(): void
    {
        $drive = $this->fakeDrive();
        $account = GoogleDriveConnection::current();
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.profile')
            ->set('photo', $this->png('me.png'))
            ->assertHasNoErrors();

        $first = $user->fresh()->profile_image;
        $this->assertStringStartsWith("drive-{$account->id}/profile-images/", $first);
        $drive->assertExists(Str::after($first, '/'));
        $this->assertSame(route('profile-photo', ['user' => $user, 'v' => basename($first)]), $user->fresh()->profileImageUrl());

        Livewire::test('pages::settings.profile')
            ->set('photo', $this->png('me-again.png'))
            ->assertHasNoErrors();

        $second = $user->fresh()->profile_image;
        $drive->assertExists(Str::after($second, '/'));
        $drive->assertMissing(Str::after($first, '/'));

        Livewire::test('pages::settings.profile')->call('removePhoto');

        $this->assertNull($user->fresh()->profile_image);
        $this->assertNull($user->fresh()->profileImageUrl());
        $drive->assertMissing(Str::after($second, '/'));
    }

    public function test_a_photo_on_the_old_public_disk_still_shows_and_is_deleted_when_replaced(): void
    {
        Storage::fake('public')->put('profile-images/old.png', 'png');
        $this->fakeDrive();
        $user = User::factory()->create(['profile_image' => 'profile-images/old.png']);

        $this->assertStringContainsString('/storage/profile-images/old.png', $user->profileImageUrl());

        $this->actingAs($user);
        Livewire::test('pages::settings.profile')
            ->set('photo', $this->png('me.png'))
            ->assertHasNoErrors();

        Storage::disk('public')->assertMissing('profile-images/old.png');
    }

    public function test_without_a_storage_account_the_photo_is_refused_and_the_old_one_kept(): void
    {
        $user = User::factory()->create(['profile_image' => 'profile-images/old.png']);

        $this->actingAs($user);
        Livewire::test('pages::settings.profile')
            ->set('photo', $this->png('me.png'))
            ->assertHasErrors(['photo']);

        $this->assertSame('profile-images/old.png', $user->fresh()->profile_image);
    }

    public function test_a_photo_on_google_drive_opens_for_signed_in_members_and_is_cached_by_the_browser(): void
    {
        $drive = $this->fakeDrive();
        $account = GoogleDriveConnection::current();
        $drive->put('profile-images/me.png', 'png-bytes');
        $user = User::factory()->create(['profile_image' => "drive-{$account->id}/profile-images/me.png"]);

        $this->get($user->profileImageUrl())->assertRedirect(route('login'));

        $response = $this->actingAs(User::factory()->create())->get($user->profileImageUrl());

        $response->assertOk();
        $this->assertSame('png-bytes', $response->streamedContent());
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
    }

    public function test_removing_a_photo_asks_for_confirmation_first(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->get(route('profile.edit'))->assertDontSee('data-test="remove-photo-button"', escape: false);

        $user->update(['profile_image' => 'profile-images/me.png']);

        $page = $this->get(route('profile.edit'))
            ->assertSee('data-test="remove-photo-button"', escape: false)
            ->assertSee("name: 'remove-photo'", escape: false)
            ->assertSee('Remove your photo?')
            ->getContent();

        // Only the dialog's confirm button removes the photo, never the Remove button itself.
        $this->assertSame(1, substr_count($page, 'wire:click="removePhoto"'));
    }

    public function test_a_chosen_photo_is_framed_before_it_uploads(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('profile.edit'))
            ->assertSee('Adjust your photo')
            ->assertSee('x-on:change="pick($event)"', escape: false)
            ->assertDontSee('wire:model="photo"', escape: false)
            ->assertSee('x-show="saving"', escape: false);
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
