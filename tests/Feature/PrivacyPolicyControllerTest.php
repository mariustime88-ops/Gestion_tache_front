<?php

namespace Tests\Feature;

use App\Models\PrivacyPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivacyPolicyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_endpoint_does_not_expose_an_unpublished_policy(): void
    {
        PrivacyPolicy::query()->create([
            'content' => 'Texte encore en brouillon.',
            'is_published' => false,
        ]);

        $this->getJson('/api/public/privacy-policy')
            ->assertOk()
            ->assertJsonPath('data.content', null)
            ->assertJsonPath('data.published_at', null);
    }

    public function test_administrator_can_publish_policy_and_public_endpoint_serves_it(): void
    {
        $administrator = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        Sanctum::actingAs($administrator);
        $content = "Confidentialité de l'organisation\n\nVos données restent protégées.";

        $this->getJson('/api/privacy-policy')
            ->assertOk()
            ->assertJsonPath('data.content', '')
            ->assertJsonPath('data.is_published', false);

        $this->putJson('/api/privacy-policy', ['content' => $content])
            ->assertOk()
            ->assertJsonPath('data.content', $content)
            ->assertJsonPath('data.is_published', true)
            ->assertJsonPath('data.published_by_id', $administrator->id);

        $this->assertDatabaseHas('privacy_policies', [
            'id' => 1,
            'content' => $content,
            'is_published' => true,
            'published_by_id' => $administrator->id,
        ]);
        $response = $this->getJson('/api/public/privacy-policy')
            ->assertOk()
            ->assertJsonPath('data.content', $content);
        $this->assertNotEmpty($response->json('data.published_at'));
    }

    public function test_non_admin_cannot_read_or_publish_the_admin_policy(): void
    {
        $director = User::factory()->create([
            'role' => 'dg',
            'profile_status' => 'active',
        ]);
        Sanctum::actingAs($director);

        $this->getJson('/api/privacy-policy')->assertForbidden();
        $this->putJson('/api/privacy-policy', ['content' => 'Texte non autorisé.'])->assertForbidden();

        $this->assertDatabaseMissing('privacy_policies', ['id' => 1]);
    }

    public function test_anonymous_user_cannot_update_the_policy(): void
    {
        $this->putJson('/api/privacy-policy', ['content' => 'Texte non autorisé.'])
            ->assertUnauthorized();

        $this->assertDatabaseMissing('privacy_policies', ['id' => 1]);
    }

    public function test_administrator_must_provide_non_empty_policy_content(): void
    {
        $administrator = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        Sanctum::actingAs($administrator);

        $this->putJson('/api/privacy-policy', ['content' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');

        $this->assertDatabaseMissing('privacy_policies', ['id' => 1]);
    }
}
