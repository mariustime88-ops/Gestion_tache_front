<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ItOrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_profiles_are_paginated_five_at_a_time(): void
    {
        $administrator = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        User::factory()->count(6)->create([
            'role' => 'agent',
            'profile_status' => 'active',
        ]);
        Sanctum::actingAs($administrator);

        $this->getJson('/api/users?page=1')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.id', $administrator->id)
            ->assertJsonPath('data.0.profile_photo_url', null)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 7);

        $this->getJson('/api/users?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('current_page', 2);
    }

    public function test_administrator_sees_only_active_members_assigned_to_a_service(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        $director = User::factory()->create(['role' => 'dg', 'profile_status' => 'active']);
        $manager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Ressource humaine',
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Ressource humaine',
            'division' => 'MRH',
        ]);
        $suspendedAgent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'suspended',
            'department' => 'Ressource humaine',
            'division' => 'MRH',
        ]);
        Sanctum::actingAs($administrator);

        $response = $this->getJson('/api/team')->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment([
                'id' => $agent->id,
                'department' => 'Ressource humaine',
                'division' => 'MRH',
            ]);

        $this->assertSame([$agent->id, $manager->id], array_column($response->json('data'), 'id'));
        $this->assertNotContains($director->id, array_column($response->json('data'), 'id'));
        $this->assertNotContains($suspendedAgent->id, array_column($response->json('data'), 'id'));
    }

    public function test_team_directory_returns_all_members_for_client_side_team_pagination(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        User::factory()->count(31)->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Ressource humaine',
        ]);
        Sanctum::actingAs($administrator);

        $this->getJson('/api/team')
            ->assertOk()
            ->assertJsonCount(31, 'data')
            ->assertJsonPath('total', 31);
    }

    public function test_administrator_assigns_pages_and_the_api_enforces_them(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        $division = Division::query()->create(['department' => 'Finance', 'name' => 'Comptabilité']);
        Sanctum::actingAs($administrator);

        $agentResponse = $this->postJson('/api/users', [
            'name' => 'Agent Finance',
            'email' => 'agent.finance@example.test',
            'password' => 'Secret1234',
            'role' => 'agent',
            'division_id' => $division->id,
            'allowed_pages' => ['dashboard', 'profile'],
        ])->assertCreated()
            ->assertJsonPath('data.user.allowed_pages', ['dashboard', 'profile']);

        $agent = User::query()->findOrFail($agentResponse->json('data.user.id'));
        Sanctum::actingAs($agent);
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/tasks')->assertForbidden();

        Sanctum::actingAs($administrator);
        $this->patchJson("/api/users/{$agent->id}", [
            'allowed_pages' => ['dashboard', 'tasks', 'profile'],
        ])->assertOk()
            ->assertJsonPath('data.user.allowed_pages', ['dashboard', 'tasks', 'profile']);

        Sanctum::actingAs($agent->fresh());
        $this->getJson('/api/tasks')->assertOk();
    }

    public function test_audit_menu_access_can_be_assigned_to_a_director_and_revoked(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        Sanctum::actingAs($administrator);

        $directorResponse = $this->postJson('/api/users', [
            'name' => 'Directeur de test',
            'email' => 'directeur.test@example.test',
            'password' => 'Secret1234',
            'role' => 'dg',
            'allowed_pages' => ['dashboard', 'audit'],
        ])->assertCreated()
            ->assertJsonPath('data.user.allowed_pages', ['dashboard', 'audit']);

        $director = User::query()->findOrFail($directorResponse->json('data.user.id'));
        Sanctum::actingAs($director);
        $this->getJson('/api/audit-logs')->assertOk();

        Sanctum::actingAs($administrator);
        $this->patchJson("/api/users/{$director->id}", [
            'allowed_pages' => ['dashboard'],
        ])->assertOk();

        Sanctum::actingAs($director->fresh());
        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_service_chief_must_select_a_service_from_the_division_catalog(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        Division::query()->create([
            'department' => 'Ressource humaine',
            'name' => 'MRH',
        ]);
        Sanctum::actingAs($administrator);

        $this->postJson('/api/users', [
            'name' => 'Chef hors service',
            'email' => 'chef.hors.service@example.test',
            'password' => 'Secret1234',
            'role' => 'manager',
            'department' => 'Service inexistant',
            'allowed_pages' => ['dashboard', 'tasks'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('department');

        $response = $this->postJson('/api/users', [
            'name' => 'Chef RH',
            'email' => 'chef.rh@example.test',
            'password' => 'Secret1234',
            'role' => 'manager',
            'department' => 'Ressource humaine',
            'allowed_pages' => ['dashboard', 'tasks'],
        ])->assertCreated()
            ->assertJsonPath('data.user.department', 'Ressource humaine');

        $this->assertDatabaseHas('users', [
            'id' => $response->json('data.user.id'),
            'department' => 'Ressource humaine',
        ]);
    }

    public function test_task_creation_returns_403_without_task_creation_permission(): void
    {
        $administrator = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        $manager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
            'allowed_pages' => ['tasks'],
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        Sanctum::actingAs($manager);

        $this->getJson('/api/tasks')->assertOk();
        $this->getJson('/api/agents')->assertForbidden();
        $this->postJson('/api/tasks', [
            'title' => 'Rapport mensuel',
            'description' => 'Préparer le rapport.',
            'assigned_to' => $agent->id,
        ])->assertForbidden();

        Sanctum::actingAs($administrator);
        $this->patchJson("/api/users/{$manager->id}", [
            'allowed_pages' => ['tasks', 'create_tasks'],
        ])->assertOk();

        Sanctum::actingAs($manager->fresh());
        $this->getJson('/api/agents')->assertOk()
            ->assertJsonFragment(['id' => $agent->id]);
        $response = $this->postJson('/api/tasks', [
            'title' => 'Rapport mensuel',
            'description' => 'Préparer le rapport.',
            'assigned_to' => $agent->id,
        ])->assertCreated()
            ->assertJsonPath('data.task.title', 'Rapport mensuel');

        $this->assertDatabaseHas('tasks', [
            'id' => $response->json('data.task.id'),
            'created_by' => $manager->id,
            'assigned_to' => $agent->id,
        ]);
    }

    public function test_administrator_can_create_and_assign_tasks_across_the_organization(): void
    {
        $administrator = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        $manager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Ressources humaines',
        ]);
        Sanctum::actingAs($administrator);

        $this->getJson('/api/agents')->assertOk()
            ->assertJsonFragment(['id' => $manager->id])
            ->assertJsonFragment(['id' => $agent->id]);
        $response = $this->postJson('/api/tasks', [
            'title' => 'Contrôle administratif',
            'description' => 'Vérifier le suivi des services.',
            'assigned_to' => $manager->id,
        ])->assertCreated()
            ->assertJsonPath('data.task.title', 'Contrôle administratif')
            ->assertJsonPath('data.task.created_by', $administrator->id)
            ->assertJsonPath('data.task.assigned_to', $manager->id);

        $this->assertDatabaseHas('tasks', [
            'id' => $response->json('data.task.id'),
            'created_by' => $administrator->id,
            'assigned_to' => $manager->id,
            'department' => 'Finance',
        ]);
    }

    public function test_administrator_cannot_grant_a_page_outside_the_users_role_scope(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        $division = Division::query()->create(['department' => 'Finance', 'name' => 'Comptabilité']);
        Sanctum::actingAs($administrator);

        $this->postJson('/api/users', [
            'name' => 'Agent Finance',
            'email' => 'agent.team@example.test',
            'password' => 'Secret1234',
            'role' => 'agent',
            'division_id' => $division->id,
            'allowed_pages' => ['team'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('allowed_pages.0');

        $this->assertDatabaseMissing('users', ['email' => 'agent.team@example.test']);
    }

    public function test_task_creation_permission_cannot_be_granted_to_an_agent(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'allowed_pages' => ['tasks'],
        ]);
        Sanctum::actingAs($administrator);

        $this->patchJson("/api/users/{$agent->id}", [
            'allowed_pages' => ['tasks', 'create_tasks'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('allowed_pages.1');

        $this->assertSame(['tasks'], $agent->fresh()->allowed_pages);
    }

    public function test_it_can_create_divisions_and_assign_a_division_lead_and_agents(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        Sanctum::actingAs($administrator);

        $divisionResponse = $this->postJson('/api/divisions', [
            'department' => 'Ressources humaines',
            'name' => 'Gestion du personnel',
        ])->assertCreated();

        $divisionId = $divisionResponse->json('data.division.id');
        $chief = $this->postJson('/api/users', [
            'name' => 'Chef de division',
            'email' => 'chef.division@example.test',
            'password' => 'Secret1234',
            'role' => 'division_manager',
            'division_id' => $divisionId,
            'allowed_pages' => ['dashboard', 'tasks', 'profile'],
        ])->assertCreated()
            ->assertJsonPath('data.user.department', 'Ressources humaines')
            ->assertJsonPath('data.user.division', 'Gestion du personnel');
        $agent = $this->postJson('/api/users', [
            'name' => 'Agent RH',
            'email' => 'agent.rh@example.test',
            'password' => 'Secret1234',
            'role' => 'agent',
            'division_id' => $divisionId,
            'allowed_pages' => ['dashboard', 'tasks', 'profile'],
        ])->assertCreated()
            ->assertJsonPath('data.user.division_id', $divisionId);

        $this->assertDatabaseHas('users', [
            'id' => $chief->json('data.user.id'),
            'division_id' => $divisionId,
            'profile_status' => 'active',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $agent->json('data.user.id'),
            'department' => 'Ressources humaines',
            'division' => 'Gestion du personnel',
        ]);

        $this->getJson('/api/divisions')
            ->assertOk()
            ->assertJsonPath('data.0.manager.name', 'Chef de division')
            ->assertJsonPath('data.0.active_members_count', 2);
        $this->getJson("/api/users/{$chief->json('data.user.id')}")
            ->assertOk()
            ->assertJsonPath('data.team_members.0.name', 'Agent RH');
        $this->patchJson("/api/divisions/{$divisionId}", [
            'department' => 'Administration',
            'name' => 'Gestion des équipes',
        ])->assertOk()
            ->assertJsonPath('data.division.name', 'Gestion des équipes');
        $this->assertDatabaseHas('users', [
            'id' => $agent->json('data.user.id'),
            'department' => 'Administration',
            'division' => 'Gestion des équipes',
            'division_id' => $divisionId,
        ]);
        $this->deleteJson("/api/divisions/{$divisionId}")
            ->assertOk()
            ->assertJsonPath('message', 'La division et ses profils ont été supprimés. Les tâches en cours sont en pause et peuvent être réaffectées.');

        $this->assertDatabaseMissing('divisions', ['id' => $divisionId]);
        $this->assertNotNull(User::withTrashed()->findOrFail($agent->json('data.user.id'))->deleted_at);
        $this->assertTrue(Hash::check('Secret1234', User::withTrashed()->findOrFail($agent->json('data.user.id'))->password));
    }

    public function test_it_can_assign_an_existing_division_chief_when_creating_or_editing_a_division(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        $source = Division::query()->create(['department' => 'Finance', 'name' => 'Comptabilité']);
        $target = Division::query()->create(['department' => 'Opérations', 'name' => 'Logistique']);
        $chief = User::factory()->create([
            'role' => 'division_manager',
            'profile_status' => 'active',
            'department' => $source->department,
            'division' => $source->name,
            'division_id' => $source->id,
        ]);
        Sanctum::actingAs($administrator);

        $this->patchJson("/api/divisions/{$target->id}", [
            'department' => $target->department,
            'name' => $target->name,
            'manager_id' => $chief->id,
        ])->assertOk()
            ->assertJsonPath('data.division.manager.id', $chief->id);

        $this->assertDatabaseHas('users', [
            'id' => $chief->id,
            'role' => 'division_manager',
            'division_id' => $target->id,
            'department' => $target->department,
            'division' => $target->name,
        ]);

        $newChief = User::factory()->create([
            'role' => 'division_manager',
            'profile_status' => 'active',
            'department' => $source->department,
            'division' => $source->name,
            'division_id' => $source->id,
        ]);
        $created = $this->postJson('/api/divisions', [
            'department' => 'Finance',
            'name' => 'Audit',
            'manager_id' => $newChief->id,
        ])->assertCreated()
            ->assertJsonPath('data.division.manager.id', $newChief->id);

        $this->assertDatabaseHas('users', [
            'id' => $newChief->id,
            'division_id' => $created->json('data.division.id'),
        ]);

        $this->patchJson("/api/divisions/{$target->id}", [
            'department' => $target->department,
            'name' => $target->name,
            'manager_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.division.manager', null);

        $this->assertDatabaseHas('users', ['id' => $chief->id, 'role' => 'agent']);
    }

    public function test_it_can_suspend_and_reactivate_profiles_without_deleting_their_history(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
            'password' => 'Secret1234',
        ]);
        $token = $agent->createToken('browser')->accessToken;
        Sanctum::actingAs($administrator);

        $this->patchJson("/api/users/{$agent->id}", ['profile_status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.user.profile_status', 'suspended');
        $this->assertDatabaseHas('users', ['id' => $agent->id, 'profile_status' => 'suspended']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);

        $this->postJson('/api/auth/login', [
            'email' => $agent->email,
            'password' => 'Secret1234',
        ])->assertForbidden()
            ->assertJsonPath('account_status', 'suspended')
            ->assertJsonPath('message', 'Votre compte est suspendu. Contactez l’administration informatique.');

        $this->patchJson("/api/users/{$agent->id}", ['profile_status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.user.profile_status', 'active');
        $this->assertDatabaseHas('users', ['id' => $agent->id, 'profile_status' => 'active']);
    }

    public function test_active_service_chiefs_can_log_in_and_receive_their_assigned_pages(): void
    {
        $chief = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
            'allowed_pages' => ['tasks', 'profile'],
            'password' => 'Secret1234',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $chief->email,
            'password' => 'Secret1234',
        ])->assertOk()
            ->assertJsonPath('data.user.role', 'manager')
            ->assertJsonPath('data.user.allowed_pages', ['tasks', 'profile'])
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $chief->id,
            'name' => 'angular-client',
        ]);
    }

    public function test_only_it_can_manage_divisions_and_profiles(): void
    {
        $director = User::factory()->create(['role' => 'dg', 'profile_status' => 'active']);
        $division = Division::query()->create(['department' => 'Finance', 'name' => 'Comptabilité']);
        Sanctum::actingAs($director);

        $this->getJson('/api/divisions')->assertForbidden();
        $this->postJson('/api/divisions', [
            'department' => 'Finance',
            'name' => 'Audit',
        ])->assertForbidden();
        $this->postJson('/api/users', [
            'name' => 'Nouvel agent',
            'email' => 'nouvel.agent@example.test',
            'password' => 'Secret1234',
            'role' => 'agent',
            'division_id' => $division->id,
        ])->assertForbidden();
        $this->deleteJson("/api/divisions/{$division->id}")->assertForbidden();
    }

    public function test_a_division_cannot_have_two_active_division_chiefs(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        $division = Division::query()->create(['department' => 'Finance', 'name' => 'Comptabilité']);
        User::factory()->create([
            'role' => 'division_manager',
            'profile_status' => 'active',
            'department' => $division->department,
            'division' => $division->name,
            'division_id' => $division->id,
        ]);
        Sanctum::actingAs($administrator);

        $this->postJson('/api/users', [
            'name' => 'Second chef',
            'email' => 'second.chef@example.test',
            'password' => 'Secret1234',
            'role' => 'division_manager',
            'division_id' => $division->id,
            'allowed_pages' => ['dashboard', 'tasks', 'profile'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('division_id');

        $suspendedChief = User::factory()->create([
            'role' => 'division_manager',
            'profile_status' => 'suspended',
            'department' => $division->department,
            'division' => $division->name,
            'division_id' => $division->id,
        ]);
        $this->patchJson("/api/users/{$suspendedChief->id}", ['profile_status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('division_id');
    }

    public function test_public_registration_must_select_an_it_managed_division(): void
    {
        $division = Division::query()->create(['department' => 'Finance', 'name' => 'Comptabilité']);

        $this->getJson('/api/public/divisions')
            ->assertOk()
            ->assertJsonPath('data.0.id', $division->id);

        $this->postJson('/api/auth/register', [
            'name' => 'Nouvel agent',
            'email' => 'inscription@example.test',
            'password' => 'Secret1234',
            'password_confirmation' => 'Secret1234',
            'division_id' => $division->id,
        ])->assertCreated()
            ->assertJsonPath('data.user.department', 'Finance')
            ->assertJsonPath('data.user.division', 'Comptabilité')
            ->assertJsonPath('data.user.profile_status', 'pending');

        $this->postJson('/api/auth/register', [
            'name' => 'Division inconnue',
            'email' => 'division.inconnue@example.test',
            'password' => 'Secret1234',
            'password_confirmation' => 'Secret1234',
            'division_id' => 999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('division_id');
    }
}
