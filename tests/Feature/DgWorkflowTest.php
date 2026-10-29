<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\Division;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DgWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dg_can_view_all_tasks_team_members_and_assign_any_operational_role(): void
    {
        $director = User::factory()->create([
            'role' => 'dg',
            'profile_status' => 'active',
            'department' => null,
        ]);
        $manager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $divisionManager = User::factory()->create([
            'role' => 'division_manager',
            'profile_status' => 'active',
            'department' => 'Opérations',
            'division' => 'Logistique',
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $itAdministrator = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        $pendingAgent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'pending',
            'department' => 'Finance',
        ]);
        $division = Division::query()->create([
            'department' => 'Finance',
            'name' => 'Comptabilité',
        ]);

        Sanctum::actingAs($director);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('total', 6)
            ->assertJsonFragment(['id' => $itAdministrator->id])
            ->assertJsonFragment(['id' => $pendingAgent->id]);
        $this->getJson('/api/divisions')
            ->assertOk()
            ->assertJsonFragment(['id' => $division->id]);
        $this->postJson('/api/users', [
            'name' => 'Profil interdit',
            'email' => 'interdit@example.test',
            'password' => 'Secret1234',
            'role' => 'agent',
        ])->assertForbidden();
        $this->patchJson("/api/users/{$agent->id}", ['profile_status' => 'suspended'])
            ->assertForbidden();
        $this->postJson('/api/divisions', [
            'department' => 'Finance',
            'name' => 'Division interdite',
        ])->assertForbidden();
        $this->patchJson("/api/divisions/{$division->id}", [
            'department' => 'Finance',
            'name' => 'Division modifiée',
        ])->assertForbidden();

        $this->getJson('/api/team')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissing(['id' => $itAdministrator->id])
            ->assertJsonMissing(['id' => $pendingAgent->id]);
        $this->getJson('/api/agents')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        foreach ([$manager, $divisionManager, $agent] as $assignee) {
            $this->postJson('/api/tasks', [
                'title' => 'Tâche pour '.$assignee->name,
                'description' => 'Suivre un travail dans l’organisation.',
                'assigned_to' => $assignee->id,
            ])->assertCreated()
                ->assertJsonPath('data.task.assigned_to', $assignee->id)
                ->assertJsonPath('data.task.department', $assignee->department);
        }

        $outsideDepartmentTask = Task::create([
            'title' => 'Tâche d’un autre service',
            'description' => 'Visible dans la vue globale du DG.',
            'department' => 'Ressources humaines',
            'created_by' => $manager->id,
            'assigned_to' => $agent->id,
            'status' => 'in_progress',
        ]);

        $this->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonFragment(['id' => $outsideDepartmentTask->id]);
        $this->getJson('/api/tasks?status=assigned&search=T%C3%A2che%20pour')
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $this->getJson('/api/team?search=Finance')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $agent->id])
            ->assertJsonFragment(['id' => $manager->id]);

        Sanctum::actingAs($manager);
        $this->getJson('/api/team')->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/divisions')->assertForbidden();
        $this->postJson('/api/tasks', [
            'title' => 'Affectation interdite',
            'description' => 'Un chef de service ne peut pas affecter à un autre responsable.',
            'assigned_to' => $divisionManager->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');
    }

    public function test_assignee_starts_and_submits_a_task_for_dg_review_and_dg_receives_updates(): void
    {
        $director = User::factory()->create([
            'role' => 'dg',
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
            'department' => 'Finance',
        ]);
        $task = Task::create([
            'title' => 'Préparer le bilan',
            'description' => 'Rassembler les données mensuelles.',
            'department' => 'Finance',
            'created_by' => $manager->id,
            'assigned_to' => $agent->id,
            'status' => 'assigned',
        ]);

        Sanctum::actingAs($manager);
        $this->postJson("/api/tasks/{$task->id}/start")->assertForbidden();

        Sanctum::actingAs($agent);
        $this->postJson("/api/tasks/{$task->id}/submit")->assertUnprocessable();
        $this->postJson("/api/tasks/{$task->id}/activities", [
            'body' => 'Je commence par vérifier les chiffres.',
        ])->assertCreated();
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'assigned',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $director->id,
        ]);
        $directorNotificationCount = $director->notifications()->count();

        $this->postJson("/api/tasks/{$task->id}/start")
            ->assertOk()
            ->assertJsonPath('data.task.status', 'in_progress');
        $this->assertSame($directorNotificationCount + 1, $director->notifications()->count());
        $this->postJson("/api/tasks/{$task->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.task.status', 'submitted');

        Sanctum::actingAs($director);
        $this->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.task.activities.0.body', 'La tâche a été envoyée à son responsable pour validation.');
        $this->postJson("/api/tasks/{$task->id}/review", [
            'decision' => 'revision',
            'comment' => 'Merci de détailler les écarts observés.',
        ])->assertOk()
            ->assertJsonPath('data.task.status', 'needs_revision')
            ->assertJsonPath('data.task.activities.0.body', 'Merci de détailler les écarts observés.');
    }

    public function test_administrator_can_assign_a_global_dg_role_without_a_department_scope(): void
    {
        $administrator = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        $user = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Comptabilité',
        ]);

        Sanctum::actingAs($administrator);
        $this->patchJson("/api/users/{$user->id}", ['role' => 'dg'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'dg')
            ->assertJsonPath('data.user.department', null)
            ->assertJsonPath('data.user.division', null);
    }
}
