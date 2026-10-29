<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Notifications\TaskNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_division_pauses_its_open_tasks_and_leaders_can_reassign_them(): void
    {
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);
        $manager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $division = Division::query()->create(['department' => 'Finance', 'name' => 'Comptabilité']);
        $chief = User::factory()->create([
            'role' => 'division_manager',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Comptabilité',
            'division_id' => $division->id,
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Comptabilité',
            'division_id' => $division->id,
        ]);
        $replacementDivision = Division::query()->create(['department' => 'Finance', 'name' => 'Trésorerie']);
        $replacementAgent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Trésorerie',
            'division_id' => $replacementDivision->id,
        ]);
        $openTask = Task::query()->create([
            'title' => 'Clôture mensuelle',
            'description' => 'Préparer les pièces comptables.',
            'department' => 'Finance',
            'division' => 'Comptabilité',
            'created_by' => $chief->id,
            'assigned_to' => $agent->id,
            'status' => 'in_progress',
        ]);
        $completedTask = Task::query()->create([
            'title' => 'Rapport terminé',
            'description' => 'Rapport finalisé.',
            'department' => 'Finance',
            'division' => 'Comptabilité',
            'created_by' => $chief->id,
            'assigned_to' => $agent->id,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($administrator);
        $this->deleteJson("/api/divisions/{$division->id}")->assertOk();

        $this->assertDatabaseHas('tasks', [
            'id' => $openTask->id,
            'status' => 'paused',
            'assigned_to' => null,
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => $completedTask->id,
            'status' => 'completed',
            'assigned_to' => $agent->id,
        ]);
        $this->assertDatabaseHas('task_activities', [
            'task_id' => $openTask->id,
            'user_id' => $administrator->id,
            'body' => 'Tâche mise en pause après la suppression de la division « Comptabilité ». Ancien responsable : '.$agent->name.'.',
        ]);
        $this->assertNotNull(User::withTrashed()->findOrFail($chief->id)->deleted_at);
        $this->assertNotNull(User::withTrashed()->findOrFail($agent->id)->deleted_at);
        $this->getJson('/api/divisions')
            ->assertOk()
            ->assertJsonPath('deletion_history.0.name', 'Comptabilité')
            ->assertJsonPath('deletion_history.0.department', 'Finance')
            ->assertJsonPath('deletion_history.0.members_count', 2)
            ->assertJsonPath('deletion_history.0.paused_tasks_count', 1)
            ->assertJsonPath('deletion_history.0.deleted_by.name', $administrator->name);

        Sanctum::actingAs($replacementAgent);
        $this->getJson("/api/tasks/{$openTask->id}/reassignment-options")->assertForbidden();

        Sanctum::actingAs($manager);
        $this->getJson("/api/tasks/{$openTask->id}/reassignment-options")
            ->assertOk()
            ->assertJsonPath('data.0.id', $replacementAgent->id);
        $this->postJson("/api/tasks/{$openTask->id}/reassign", [
            'assigned_to' => $replacementAgent->id,
        ])->assertOk()
            ->assertJsonPath('data.task.status', 'assigned')
            ->assertJsonPath('data.task.assignee.name', $replacementAgent->name);

        $this->assertDatabaseHas('tasks', [
            'id' => $openTask->id,
            'status' => 'assigned',
            'assigned_to' => $replacementAgent->id,
            'created_by' => $manager->id,
            'division' => 'Trésorerie',
        ]);
    }

    public function test_agent_can_apply_to_a_project_and_exchange_messages_with_its_owner(): void
    {
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

        Sanctum::actingAs($manager);
        $projectResponse = $this->postJson('/api/projects', [
            'title' => 'Améliorer les outils de suivi',
            'summary' => 'Créer une meilleure visibilité sur les demandes.',
            'description' => 'Étudier les besoins et proposer une solution.',
            'organization_name' => 'Direction des opérations',
        ])->assertCreated()
            ->assertJsonPath('data.project.status', 'open');

        $projectId = $projectResponse->json('data.project.id');
        Sanctum::actingAs($agent);
        $this->getJson('/api/projects')->assertOk()
            ->assertJsonPath('data.0.id', $projectId);

        $applicationResponse = $this->postJson("/api/projects/{$projectId}/applications", [
            'type' => 'application',
            'message' => 'Je souhaite contribuer à cette initiative.',
        ])->assertCreated()
            ->assertJsonPath('data.application.type', 'application')
            ->assertJsonPath('data.application.messages.0.body', 'Je souhaite contribuer à cette initiative.');

        $applicationId = $applicationResponse->json('data.application.id');
        $this->postJson("/api/projects/{$projectId}/applications", [
            'type' => 'application',
            'message' => 'Une candidature en double.',
        ])->assertUnprocessable();

        Sanctum::actingAs($manager);
        $this->getJson("/api/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('data.project.applications.0.id', $applicationId);
        $this->postJson("/api/project-applications/{$applicationId}/messages", [
            'message' => 'Merci pour votre intérêt. Pouvez-vous préciser votre expérience ?',
        ])->assertCreated();

        Sanctum::actingAs($agent);
        $this->getJson("/api/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('data.project.applications.0.messages.0.body', 'Merci pour votre intérêt. Pouvez-vous préciser votre expérience ?');

        Sanctum::actingAs($manager);
        $this->patchJson("/api/projects/{$projectId}", ['status' => 'closed'])->assertOk();
        Sanctum::actingAs($agent);
        $this->getJson("/api/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('data.project.status', 'closed')
            ->assertJsonPath('data.project.applications.0.id', $applicationId);
    }

    public function test_project_owner_can_review_an_application_and_agents_cannot_access_other_projects(): void
    {
        $owner = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $otherManager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Operations',
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
        ]);
        $project = Project::create([
            'title' => 'Projet confidentiel',
            'summary' => 'Une initiative réservée à ses participants.',
            'description' => 'Détails du projet.',
            'organization_name' => 'Direction',
            'department' => 'Finance',
            'owner_id' => $owner->id,
            'status' => 'open',
        ]);

        Sanctum::actingAs($agent);
        $applicationResponse = $this->postJson("/api/projects/{$project->id}/applications", [
            'type' => 'application',
            'message' => 'Je souhaite rejoindre ce projet.',
        ])->assertCreated();
        $applicationId = $applicationResponse->json('data.application.id');

        Sanctum::actingAs($otherManager);
        $this->getJson("/api/projects/{$project->id}")->assertNotFound();
        $this->postJson("/api/project-applications/{$applicationId}/review", [
            'decision' => 'accepted',
        ])->assertForbidden();

        Sanctum::actingAs($owner);
        $this->postJson("/api/project-applications/{$applicationId}/review", [
            'decision' => 'accepted',
        ])->assertOk()
            ->assertJsonPath('data.application.status', 'accepted');
    }

    public function test_agent_cannot_apply_to_a_closed_project(): void
    {
        $owner = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
        ]);
        $project = Project::create([
            'title' => 'Projet clôturé',
            'summary' => 'Les demandes sont terminées.',
            'description' => 'Aucune nouvelle candidature.',
            'organization_name' => 'Direction',
            'department' => 'Finance',
            'owner_id' => $owner->id,
            'status' => 'closed',
        ]);

        Sanctum::actingAs($agent);
        $this->getJson('/api/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/projects/{$project->id}/applications", [
            'type' => 'inquiry',
            'message' => 'Question sur le projet.',
        ])->assertUnprocessable();
    }

    public function test_division_manager_can_only_assign_and_review_tasks_in_their_division(): void
    {
        $divisionManager = User::factory()->create([
            'role' => 'division_manager',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Comptabilité',
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Comptabilité',
        ]);
        $otherAgent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Trésorerie',
        ]);

        Sanctum::actingAs($divisionManager);
        $this->getJson('/api/agents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $agent->id);
        $this->getJson('/api/projects')->assertForbidden();

        $this->postJson('/api/tasks', [
            'title' => 'Tâche hors division',
            'description' => 'Cette affectation ne doit pas être autorisée.',
            'assigned_to' => $otherAgent->id,
        ])->assertUnprocessable();
        $taskResponse = $this->postJson('/api/tasks', [
            'title' => 'Vérifier les écritures',
            'description' => 'Contrôler les écritures du mois.',
            'assigned_to' => $agent->id,
        ])->assertCreated()
            ->assertJsonPath('data.task.division', 'Comptabilité');

        $taskId = $taskResponse->json('data.task.id');
        $outsideDivisionTask = Task::create([
            'title' => 'Tâche de trésorerie',
            'description' => 'Cette tâche appartient à une autre division.',
            'department' => 'Finance',
            'division' => 'Trésorerie',
            'created_by' => $divisionManager->id,
            'assigned_to' => $otherAgent->id,
            'status' => 'assigned',
        ]);
        Sanctum::actingAs($divisionManager);
        $this->getJson('/api/tasks')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $taskId);
        $this->getJson("/api/tasks/{$outsideDivisionTask->id}")->assertForbidden();

        Sanctum::actingAs($agent);
        $this->postJson("/api/tasks/{$taskId}/start")->assertOk()
            ->assertJsonPath('data.task.status', 'in_progress');
        $this->postJson("/api/tasks/{$taskId}/submit")
            ->assertOk()
            ->assertJsonPath('data.task.status', 'submitted');

        Sanctum::actingAs($divisionManager);
        $this->postJson("/api/tasks/{$taskId}/review", [
            'decision' => 'accepted',
            'comment' => 'Validé pour la division.',
        ])->assertOk()
            ->assertJsonPath('data.task.status', 'completed');
    }

    public function test_service_manager_can_view_and_review_tasks_created_by_a_division_manager(): void
    {
        $serviceManager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $divisionManager = User::factory()->create([
            'role' => 'division_manager',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Comptabilité',
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Comptabilité',
        ]);

        Sanctum::actingAs($divisionManager);
        $taskResponse = $this->postJson('/api/tasks', [
            'title' => 'Rapport de division',
            'description' => 'Préparer le rapport de fin de mois.',
            'assigned_to' => $agent->id,
        ])->assertCreated();
        $taskId = $taskResponse->json('data.task.id');
        $otherDepartmentManager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Ressources humaines',
        ]);
        $otherDepartmentAgent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Ressources humaines',
            'division' => 'Recrutement',
        ]);
        $outsideServiceTask = Task::create([
            'title' => 'Dossier de recrutement',
            'description' => 'Cette tâche appartient à un autre service.',
            'department' => 'Ressources humaines',
            'division' => 'Recrutement',
            'created_by' => $otherDepartmentManager->id,
            'assigned_to' => $otherDepartmentAgent->id,
            'status' => 'assigned',
        ]);
        Sanctum::actingAs($agent);
        $this->postJson("/api/tasks/{$taskId}/start")->assertOk();
        $this->postJson("/api/tasks/{$taskId}/submit")->assertOk();

        Sanctum::actingAs($serviceManager);
        $this->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $taskId);
        $this->getJson("/api/tasks/{$outsideServiceTask->id}")->assertForbidden();
        $this->postJson("/api/tasks/{$taskId}/review", [
            'decision' => 'revision',
            'comment' => 'Merci de compléter le détail.',
        ])->assertOk()
            ->assertJsonPath('data.task.status', 'needs_revision');
    }

    public function test_registered_agents_cannot_claim_reserved_executive_emails_and_email_changes_are_rejected(): void
    {
        $division = Division::query()->create(['department' => 'Finance', 'name' => 'Comptabilité']);
        $this->postJson('/api/auth/register', [
            'name' => 'Imposteur',
            'email' => 'DirectionGeneral@Gmail.com',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
            'division_id' => $division->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $director = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
            'email' => 'directiongeneral@gmail.com',
        ]);
        Sanctum::actingAs($director);

        $this->patchJson("/api/users/{$director->id}", [
            'email' => 'new-address@gmail.com',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('users', [
            'id' => $director->id,
            'email' => 'directiongeneral@gmail.com',
        ]);
    }

    public function test_initial_role_provisioning_refuses_to_overwrite_existing_accounts(): void
    {
        $director = User::factory()->create([
            'role' => 'admin',
            'email' => 'directiongeneral@gmail.com',
            'password' => 'existing-secret',
        ]);

        $this->artisan('app:provision-initial-roles')
            ->expectsOutputToContain('No accounts were created.')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'chefservice@gmail.com']);
        $this->assertDatabaseMissing('users', ['email' => 'chefdivision@gmail.com']);
        $this->assertSame('directiongeneral@gmail.com', $director->fresh()->email);
    }

    public function test_initial_role_provisioning_creates_three_active_accounts_with_one_shared_password(): void
    {
        $this->artisan('app:provision-initial-roles')
            ->expectsQuestion('Shared password for the three accounts', 'a-secure-password')
            ->expectsQuestion('Confirm the shared password', 'a-secure-password')
            ->expectsOutputToContain('Created three active role accounts.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'directiongeneral@gmail.com',
            'role' => 'dg',
            'profile_status' => 'active',
        ]);
        $division = Division::query()
            ->where('department', 'Service général')
            ->where('name', 'Division générale')
            ->firstOrFail();
        $this->assertDatabaseHas('users', [
            'email' => 'chefservice@gmail.com',
            'role' => 'manager',
            'department' => 'Service général',
            'profile_status' => 'active',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'chefdivision@gmail.com',
            'role' => 'division_manager',
            'department' => 'Service général',
            'division' => 'Division générale',
            'division_id' => $division->id,
            'profile_status' => 'active',
        ]);

        foreach (['directiongeneral@gmail.com', 'chefservice@gmail.com', 'chefdivision@gmail.com'] as $email) {
            $this->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'a-secure-password',
            ])->assertOk();
        }
    }

    public function test_agent_registration_waits_for_administrator_approval(): void
    {
        $division = Division::query()->create(['department' => 'Finance', 'name' => 'Comptabilité']);
        $this->postJson('/api/auth/register', [
            'name' => 'Agent One',
            'email' => 'agent@example.test',
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
            'division_id' => $division->id,
        ])->assertCreated()
            ->assertJsonPath('data.user.role', 'agent')
            ->assertJsonPath('data.user.profile_status', 'pending')
            ->assertJsonPath('data.user.division', 'Comptabilité');

        $this->postJson('/api/auth/login', [
            'email' => 'agent@example.test',
            'password' => 'a-secure-password',
        ])->assertForbidden()
            ->assertJsonPath('account_status', 'pending');
    }

    public function test_manager_assigns_agent_and_agent_can_submit_for_review(): void
    {
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

        Sanctum::actingAs($manager);
        $taskResponse = $this->postJson('/api/tasks', [
            'title' => 'Préparer le rapport',
            'description' => 'Rassembler les éléments nécessaires au rapport mensuel.',
            'instructions' => 'Transmettre le document avant la date limite.',
            'assigned_to' => $agent->id,
        ])->assertCreated()
            ->assertJsonPath('data.task.status', 'assigned');

        $taskId = $taskResponse->json('data.task.id');
        Sanctum::actingAs($agent);

        $this->postJson("/api/tasks/{$taskId}/start")
            ->assertOk()
            ->assertJsonPath('data.task.status', 'in_progress');
        $this->postJson("/api/tasks/{$taskId}/submit")
            ->assertOk()
            ->assertJsonPath('data.task.status', 'submitted');

        Sanctum::actingAs($manager);
        $this->postJson("/api/tasks/{$taskId}/review", [
            'decision' => 'accepted',
            'comment' => 'Travail validé.',
        ])->assertOk()
            ->assertJsonPath('data.task.status', 'completed');

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'status' => 'completed',
            'created_by' => $manager->id,
            'assigned_to' => $agent->id,
        ]);
    }

    public function test_admin_can_reject_an_active_task_after_assignment(): void
    {
        $manager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Operations',
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Operations',
        ]);
        $admin = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);

        Sanctum::actingAs($manager);
        $task = Task::create([
            'title' => 'Contrôle des dossiers',
            'description' => 'Vérifier les dossiers en attente.',
            'department' => 'Operations',
            'created_by' => $manager->id,
            'assigned_to' => $agent->id,
            'status' => 'in_progress',
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/tasks/{$task->id}/admin-rejection", [
            'reason' => 'Cette tâche doit être suspendue.',
        ])->assertOk()
            ->assertJsonPath('data.task.status', 'rejected');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'rejected',
            'rejected_by' => $admin->id,
            'rejected_reason' => 'Cette tâche doit être suspendue.',
        ]);
    }

    public function test_manager_cannot_assign_a_task_outside_their_department(): void
    {
        $manager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Operations',
        ]);

        Sanctum::actingAs($manager);
        $this->postJson('/api/tasks', [
            'title' => 'Préparer le rapport',
            'description' => 'Rassembler les éléments nécessaires.',
            'assigned_to' => $agent->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_to');
    }

    public function test_agent_cannot_read_another_agents_task(): void
    {
        $manager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $owner = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $otherAgent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $task = Task::create([
            'title' => 'Rapport confidentiel',
            'description' => 'Document réservé à l’agent affecté.',
            'department' => 'Finance',
            'created_by' => $manager->id,
            'assigned_to' => $owner->id,
            'status' => 'assigned',
        ]);

        Sanctum::actingAs($otherAgent);
        $this->getJson("/api/tasks/{$task->id}")->assertForbidden();
    }

    public function test_administrator_can_validate_a_pending_profile(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'pending',
            'department' => 'Finance',
        ]);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/users/{$agent->id}", [
            'profile_status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.user.profile_status', 'active');

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $agent->id,
            'type' => TaskNotification::class,
        ]);
    }

    public function test_administrator_can_edit_task_details_and_reassign_within_the_service(): void
    {
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
        $newAgent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $admin = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        $task = Task::create([
            'title' => 'Ancien intitulé',
            'description' => 'Description initiale.',
            'department' => 'Finance',
            'created_by' => $manager->id,
            'assigned_to' => $agent->id,
            'status' => 'assigned',
        ]);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/tasks/{$task->id}", [
            'title' => 'Nouvel intitulé',
            'assigned_to' => $newAgent->id,
        ])->assertOk()
            ->assertJsonPath('data.task.title', 'Nouvel intitulé')
            ->assertJsonPath('data.task.assigned_to', $newAgent->id);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'created_by' => $manager->id,
            'assigned_to' => $newAgent->id,
            'department' => 'Finance',
        ]);
    }

    public function test_manager_editing_a_task_notifies_its_existing_assignee(): void
    {
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
            'title' => 'Rapport initial',
            'description' => 'Description.',
            'department' => 'Finance',
            'created_by' => $manager->id,
            'assigned_to' => $agent->id,
            'status' => 'assigned',
        ]);
        Notification::fake([TaskNotification::class]);

        Sanctum::actingAs($manager);
        $this->patchJson("/api/tasks/{$task->id}", [
            'title' => 'Rapport actualisé',
        ])->assertOk()
            ->assertJsonPath('data.task.title', 'Rapport actualisé');

        Notification::assertSentTo(
            $agent,
            TaskNotification::class,
            fn (TaskNotification $notification): bool => $notification->title === 'Tâche modifiée'
                && $notification->taskId === $task->id,
        );
    }

    public function test_dg_can_edit_a_task_they_created_and_notify_its_assignee(): void
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
            'title' => 'Rapport initial',
            'description' => 'Description.',
            'department' => 'Finance',
            'created_by' => $director->id,
            'assigned_to' => $agent->id,
            'status' => 'assigned',
        ]);
        Notification::fake([TaskNotification::class]);

        Sanctum::actingAs($director);
        $this->patchJson("/api/tasks/{$task->id}", [
            'title' => 'Rapport actualisé',
            'created_by' => $manager->id,
            'assigned_to' => $agent->id,
        ])->assertOk()
            ->assertJsonPath('data.task.title', 'Rapport actualisé')
            ->assertJsonPath('data.task.created_by', $director->id);

        Notification::assertSentTo(
            $agent,
            TaskNotification::class,
            fn (TaskNotification $notification): bool => $notification->title === 'Tâche modifiée'
                && $notification->taskId === $task->id,
        );
    }

    public function test_manager_deleting_a_task_removes_its_history_and_notifies_the_assignee(): void
    {
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
            'title' => 'Rapport à supprimer',
            'description' => 'Description.',
            'department' => 'Finance',
            'created_by' => $manager->id,
            'assigned_to' => $agent->id,
            'status' => 'assigned',
        ]);
        $attachmentPath = 'task-attachments/rapport.pdf';
        Storage::fake();
        Storage::put($attachmentPath, 'contenu du document');
        $activity = TaskActivity::create([
            'task_id' => $task->id,
            'user_id' => $manager->id,
            'body' => 'Document joint.',
            'attachment_path' => $attachmentPath,
            'attachment_name' => 'rapport.pdf',
        ]);
        Notification::fake([TaskNotification::class]);

        Sanctum::actingAs($manager);
        $this->deleteJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('message', 'La tâche a été supprimée.');

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('task_activities', ['id' => $activity->id]);
        $this->assertFalse(Storage::exists($attachmentPath));
        Notification::assertSentTo(
            $agent,
            TaskNotification::class,
            fn (TaskNotification $notification): bool => $notification->title === 'Tâche supprimée'
                && $notification->taskId === null,
        );
    }

    public function test_manager_cannot_delete_a_task_outside_their_department(): void
    {
        $manager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $otherManager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Ressources humaines',
        ]);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Ressources humaines',
        ]);
        $task = Task::create([
            'title' => 'Tâche protégée',
            'description' => 'Description.',
            'department' => 'Ressources humaines',
            'created_by' => $otherManager->id,
            'assigned_to' => $agent->id,
            'status' => 'assigned',
        ]);

        Sanctum::actingAs($manager);
        $this->deleteJson("/api/tasks/{$task->id}")->assertForbidden();

        $this->assertModelExists($task);
    }

    public function test_task_requester_and_assignee_can_exchange_messages_and_receive_notifications(): void
    {
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
        $this->postJson("/api/tasks/{$task->id}/activities", [
            'body' => 'Pouvez-vous préciser les données à inclure ?',
        ])->assertCreated();

        Sanctum::actingAs($agent);
        $this->postJson("/api/tasks/{$task->id}/activities", [
            'body' => 'Le rapport inclura les chiffres du mois précédent.',
        ])->assertCreated();

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'user_id' => $manager->id,
            'body' => 'Pouvez-vous préciser les données à inclure ?',
        ]);
        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'user_id' => $agent->id,
            'body' => 'Le rapport inclura les chiffres du mois précédent.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $agent->id,
            'type' => TaskNotification::class,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $manager->id,
            'type' => TaskNotification::class,
        ]);
    }

    public function test_assignee_notification_offers_start_action_only_while_task_is_startable(): void
    {
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
        $agent->notify(new TaskNotification('Nouvelle tâche', 'Une tâche vous a été confiée.', $task->id));
        Sanctum::actingAs($agent);

        $this->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('data.0.data.can_start', true);
        $this->postJson("/api/tasks/{$task->id}/start")->assertOk()
            ->assertJsonPath('data.task.status', 'in_progress');
        $this->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('data.0.data.can_start', false);
    }

    public function test_division_manager_assigned_a_task_can_open_and_start_it(): void
    {
        $director = User::factory()->create([
            'role' => 'dg',
            'profile_status' => 'active',
        ]);
        $divisionManager = User::factory()->create([
            'role' => 'division_manager',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Comptabilité',
        ]);
        $task = Task::create([
            'title' => 'Vérifier les écritures',
            'description' => 'Contrôler les écritures du mois.',
            'department' => 'Finance',
            'division' => 'Comptabilité',
            'created_by' => $director->id,
            'assigned_to' => $divisionManager->id,
            'status' => 'assigned',
        ]);

        Sanctum::actingAs($divisionManager);
        $this->getJson("/api/tasks/{$task->id}")->assertOk()
            ->assertJsonPath('data.task.status', 'assigned');
        $this->postJson("/api/tasks/{$task->id}/start")->assertOk()
            ->assertJsonPath('data.task.status', 'in_progress');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_administrator_cannot_activate_a_manager_without_a_department(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        $pendingAgent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'pending',
            'department' => null,
        ]);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/users/{$pendingAgent->id}", [
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('department');
    }
}
