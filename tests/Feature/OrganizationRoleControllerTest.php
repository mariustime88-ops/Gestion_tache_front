<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TaskNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationRoleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_role_is_inserted_above_its_parent_and_supervises_its_scope(): void
    {
        $administrator = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        Sanctum::actingAs($administrator);

        $role = $this->postJson('/api/organization-roles', [
            'name' => 'Responsable régional',
            'parent_slug' => 'manager',
        ])->assertCreated()
            ->assertJsonPath('data.role.capability_role', 'manager')
            ->json('data.role');

        $this->assertDatabaseHas('organization_roles', [
            'slug' => $role['slug'],
            'parent_slug' => 'dg',
            'capability_role' => 'manager',
            'is_system' => false,
        ]);
        $this->assertDatabaseHas('organization_roles', [
            'slug' => 'manager',
            'parent_slug' => $role['slug'],
        ]);

        $customManager = $this->postJson('/api/users', [
            'name' => 'Responsable régional',
            'email' => 'regional@example.test',
            'password' => 'Secret1234',
            'role' => $role['slug'],
            'department' => 'Finance',
            'allowed_pages' => ['dashboard', 'tasks', 'create_tasks', 'profile'],
        ])->assertCreated()
            ->assertJsonPath('data.user.capability_role', 'manager')
            ->json('data.user');
        $this->assertDatabaseHas('users', [
            'id' => $customManager['id'],
            'role' => $role['slug'],
            'department' => 'Finance',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'regional@example.test',
            'password' => 'Secret1234',
        ])->assertOk()
            ->assertJsonPath('data.user.role', $role['slug'])
            ->assertJsonPath('data.user.capability_role', 'manager')
            ->assertJsonPath('data.user.allowed_pages', ['dashboard', 'tasks', 'create_tasks', 'profile']);

        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $outsideAgent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Ressources humaines',
        ]);
        Sanctum::actingAs(User::query()->findOrFail($customManager['id']));
        Notification::fake([TaskNotification::class]);

        $this->getJson('/api/agents')
            ->assertOk()
            ->assertJsonFragment(['id' => $agent->id])
            ->assertJsonMissing(['id' => $outsideAgent->id]);
        $taskResponse = $this->postJson('/api/tasks', [
            'title' => 'Rapport régional',
            'description' => 'Préparer le rapport du service.',
            'assigned_to' => $agent->id,
        ])->assertCreated()
            ->assertJsonPath('data.task.assigned_to', $agent->id)
            ->assertJsonPath('data.task.activities.0.user.capability_role', 'manager');
        $this->assertDatabaseHas('tasks', [
            'id' => $taskResponse->json('data.task.id'),
            'assigned_to' => $agent->id,
            'department' => 'Finance',
        ]);
        Notification::assertSentTo([$agent], TaskNotification::class);

        $this->postJson('/api/tasks', [
            'title' => 'Tâche hors périmètre',
            'description' => 'Cette affectation doit être refusée.',
            'assigned_to' => $outsideAgent->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');
        $this->assertDatabaseMissing('tasks', ['title' => 'Tâche hors périmètre']);
    }

    public function test_returns_401_when_an_unauthenticated_user_attempts_to_create_a_role(): void
    {
        $this->postJson('/api/organization-roles', [
            'name' => 'Responsable régional',
            'parent_slug' => 'manager',
        ])->assertUnauthorized();
    }

    public function test_returns_403_when_a_director_attempts_to_create_a_role(): void
    {
        $director = User::factory()->create([
            'role' => 'dg',
            'profile_status' => 'active',
        ]);
        Sanctum::actingAs($director);

        $this->postJson('/api/organization-roles', [
            'name' => 'Responsable régional',
            'parent_slug' => 'manager',
        ])->assertForbidden();
    }

    public function test_returns_422_when_administrator_selects_admin_as_role_parent(): void
    {
        $administrator = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        Sanctum::actingAs($administrator);

        $this->postJson('/api/organization-roles', [
            'name' => 'Administrateur supérieur',
            'parent_slug' => 'admin',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('parent_slug');
    }
}
