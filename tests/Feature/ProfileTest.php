<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_name_and_photo_without_changing_email(): void
    {
        Storage::fake('public');
        $user = User::factory()->create([
            'email' => 'agent@example.com',
            'profile_status' => 'active',
        ]);
        Sanctum::actingAs($user);

        $response = $this->post('/api/profile', [
            '_method' => 'PATCH',
            'name' => 'Nouveau nom',
            'email' => 'autre@example.com',
            'photo' => UploadedFile::fake()->image('portrait.png'),
        ])->assertOk()
            ->assertJsonPath('data.user.name', 'Nouveau nom')
            ->assertJsonPath('data.user.email', 'agent@example.com');

        $user->refresh();

        $this->assertSame('Nouveau nom', $user->name);
        $this->assertSame('agent@example.com', $user->email);
        $this->assertNotNull($user->profile_photo_path);
        Storage::disk('public')->assertExists($user->profile_photo_path);
        $this->assertStringContainsString('/storage/profile-photos/', $response->json('data.user.profile_photo_url'));
        $this->assertArrayNotHasKey('profile_photo_path', $user->toArray());

        $previousPhotoPath = $user->profile_photo_path;
        $this->post('/api/profile', [
            '_method' => 'PATCH',
            'name' => 'Nouveau nom',
            'photo' => UploadedFile::fake()->image('nouveau-portrait.png'),
        ])->assertOk();

        $user->refresh();
        $this->assertNotSame($previousPhotoPath, $user->profile_photo_path);
        Storage::disk('public')->assertMissing($previousPhotoPath);
        Storage::disk('public')->assertExists($user->profile_photo_path);
    }

    public function test_password_change_requires_the_correct_current_password_and_confirmation(): void
    {
        $user = User::factory()->create([
            'password' => 'old-password',
            'profile_status' => 'active',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/profile', [
            'name' => $user->name,
            'current_password' => 'incorrect-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->patchJson('/api/profile', [
            'name' => $user->name,
            'current_password' => 'old-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'different-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->patchJson('/api/profile', [
            'name' => $user->name,
            'current_password' => 'old-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_profile_update_requires_authentication_and_rejects_invalid_photo(): void
    {
        $this->patchJson('/api/profile', ['name' => 'Nouveau nom'])->assertUnauthorized();

        $user = User::factory()->create(['profile_status' => 'active']);
        Sanctum::actingAs($user);

        $this->post('/api/profile', [
            '_method' => 'PATCH',
            'name' => 'Nouveau nom',
            'photo' => UploadedFile::fake()->create('document.pdf', 20, 'application/pdf'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        $this->assertSame($user->name, $user->fresh()->name);
    }
}
