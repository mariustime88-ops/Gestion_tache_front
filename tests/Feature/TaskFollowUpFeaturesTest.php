<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Division;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskFollowUpFeaturesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{manager: User, agent: User}
     */
    private function teamWithTask(array $taskOverrides = []): array
    {
        $manager = User::factory()->create([
            'role' => 'manager',
            'profile_status' => 'active',
            'department' => 'Finance',
        ]);
        $division = Division::query()->create(['department' => 'Finance', 'name' => 'Comptabilité']);
        $agent = User::factory()->create([
            'role' => 'agent',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Comptabilité',
            'division_id' => $division->id,
        ]);

        return ['manager' => $manager, 'agent' => $agent, 'task' => Task::query()->create([
            'title' => 'Clôture mensuelle',
            'description' => 'Préparer les pièces comptables.',
            'department' => 'Finance',
            'division' => 'Comptabilité',
            'created_by' => $manager->id,
            'assigned_to' => $agent->id,
            'status' => 'in_progress',
            ...$taskOverrides,
        ])];
    }

    public function test_a_task_is_flagged_as_overdue_when_its_deadline_has_passed(): void
    {
        ['manager' => $manager, 'task' => $task] = $this->teamWithTask(['due_at' => now()->subDay()]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/tasks?overdue=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $task->id)
            ->assertJsonPath('data.0.is_overdue', true);
    }

    public function test_the_assignee_can_request_an_extension_and_the_task_owner_can_approve_it(): void
    {
        Notification::fake();
        ['manager' => $manager, 'agent' => $agent, 'task' => $task] = $this->teamWithTask([
            'due_at' => now()->addDays(2),
        ]);
        $newDate = now()->addDays(10)->toDateString();

        Sanctum::actingAs($agent);
        $this->postJson("/api/tasks/{$task->id}/extension-request", [
            'requested_due_at' => $newDate,
            'reason' => 'Pièces justificatives manquantes.',
        ])->assertOk();

        $this->assertSame('pending', $task->fresh()->extension_status);

        Sanctum::actingAs($manager);
        $this->postJson("/api/tasks/{$task->id}/extension-decision", ['decision' => 'approved'])->assertOk();

        $this->assertSame('approved', $task->fresh()->extension_status);
        $this->assertSame($newDate, $task->fresh()->due_at->toDateString());
        Notification::assertSentTo($manager, TaskNotification::class);
        Notification::assertSentTo($agent, TaskNotification::class);
    }

    public function test_a_refused_extension_requires_a_reason(): void
    {
        ['manager' => $manager, 'agent' => $agent, 'task' => $task] = $this->teamWithTask([
            'due_at' => now()->addDays(2),
        ]);

        Sanctum::actingAs($agent);
        $this->postJson("/api/tasks/{$task->id}/extension-request", [
            'requested_due_at' => now()->addDays(8)->toDateString(),
            'reason' => 'Charge de travail importante.',
        ])->assertOk();

        Sanctum::actingAs($manager);
        $this->postJson("/api/tasks/{$task->id}/extension-decision", ['decision' => 'rejected'])
            ->assertUnprocessable();
    }

    public function test_only_administrators_and_the_dg_can_read_the_audit_log(): void
    {
        ['manager' => $manager] = $this->teamWithTask();
        $administrator = User::factory()->create(['role' => 'admin', 'profile_status' => 'active']);

        Sanctum::actingAs($manager);
        $this->getJson('/api/audit-logs')->assertForbidden();

        Sanctum::actingAs($administrator);
        $this->getJson('/api/audit-logs')->assertOk();
    }

    public function test_audit_log_entries_cannot_be_modified_or_deleted(): void
    {
        $this->teamWithTask();
        $entry = AuditLog::query()->firstOrFail();

        $entry->description = 'Falsifié';
        $entry->save();
        $entry->delete();

        $this->assertNotSame('Falsifié', $entry->fresh()->description);
        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id]);
    }

    public function test_the_reminder_command_notifies_the_assignee_once(): void
    {
        Notification::fake();
        ['agent' => $agent] = $this->teamWithTask(['due_at' => now()->addDays(2)->setTime(12, 0)]);

        $this->artisan('tasks:send-reminders')->assertSuccessful();
        $this->artisan('tasks:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($agent, TaskNotification::class, 1);
    }

    public function test_tasks_can_be_exported_as_csv(): void
    {
        ['manager' => $manager] = $this->teamWithTask(['priority' => 'urgent']);

        Sanctum::actingAs($manager);

        $response = $this->get('/api/tasks/export');

        $response->assertOk();
        $this->assertStringContainsString('Clôture mensuelle', $response->streamedContent());
    }

    public function test_a_review_that_asks_for_changes_or_refuses_requires_a_reason(): void
    {
        ['manager' => $manager, 'task' => $task] = $this->teamWithTask(['status' => 'submitted']);

        Sanctum::actingAs($manager);

        $this->postJson("/api/tasks/{$task->id}/review", ['decision' => 'revision'])->assertUnprocessable();
        $this->postJson("/api/tasks/{$task->id}/review", ['decision' => 'rejected'])->assertUnprocessable();
        $this->postJson("/api/tasks/{$task->id}/review", ['decision' => 'accepted'])->assertOk();
    }

    public function test_the_task_requester_can_review_the_task_but_another_administrator_cannot(): void
    {
        ['task' => $task] = $this->teamWithTask(['status' => 'submitted']);
        $requester = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        $otherAdministrator = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);
        $task->update(['created_by' => $requester->id]);

        Sanctum::actingAs($otherAdministrator);
        $this->postJson("/api/tasks/{$task->id}/review", ['decision' => 'accepted'])->assertForbidden();

        Sanctum::actingAs($requester);
        $this->postJson("/api/tasks/{$task->id}/review", ['decision' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.task.status', 'completed');
    }

    public function test_the_assignee_can_resume_a_rejected_task_and_submit_it_for_review_again(): void
    {
        ['manager' => $manager, 'agent' => $agent, 'task' => $task] = $this->teamWithTask([
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($manager);
        $this->postJson("/api/tasks/{$task->id}/review", [
            'decision' => 'rejected',
            'comment' => 'Veuillez corriger les chiffres.',
        ])->assertOk()
            ->assertJsonPath('data.task.status', 'rejected');

        Sanctum::actingAs($agent);
        $this->postJson("/api/tasks/{$task->id}/start", [])
            ->assertOk()
            ->assertJsonPath('data.task.status', 'in_progress')
            ->assertJsonPath('data.task.rejected_reason', null);
        $this->postJson("/api/tasks/{$task->id}/submit", [])
            ->assertOk()
            ->assertJsonPath('data.task.status', 'submitted');

        Sanctum::actingAs($manager);
        $this->postJson("/api/tasks/{$task->id}/review", [
            'decision' => 'accepted',
        ])->assertOk()
            ->assertJsonPath('data.task.status', 'completed');
    }

    public function test_the_assignee_can_resume_a_task_returned_for_revision_and_resubmit_it(): void
    {
        ['manager' => $manager, 'agent' => $agent, 'task' => $task] = $this->teamWithTask([
            'status' => 'submitted',
        ]);

        Sanctum::actingAs($manager);
        $this->postJson("/api/tasks/{$task->id}/review", [
            'decision' => 'revision',
            'comment' => 'Veuillez compléter les justificatifs.',
        ])->assertOk()
            ->assertJsonPath('data.task.status', 'needs_revision');

        Sanctum::actingAs($agent);
        $this->postJson("/api/tasks/{$task->id}/start", [])
            ->assertOk()
            ->assertJsonPath('data.task.status', 'in_progress');
        $this->postJson("/api/tasks/{$task->id}/submit", [])
            ->assertOk()
            ->assertJsonPath('data.task.status', 'submitted');
    }

    public function test_the_assignee_cannot_resume_a_task_rejected_by_an_administrator(): void
    {
        ['agent' => $agent, 'task' => $task] = $this->teamWithTask([
            'status' => 'in_progress',
        ]);
        $administrator = User::factory()->create([
            'role' => 'admin',
            'profile_status' => 'active',
        ]);

        Sanctum::actingAs($administrator);
        $this->postJson("/api/tasks/{$task->id}/admin-rejection", [
            'reason' => 'Cette tâche doit être clôturée.',
        ])->assertOk();

        Sanctum::actingAs($agent);
        $this->postJson("/api/tasks/{$task->id}/start", [])->assertUnprocessable();
    }

    public function test_skipping_a_level_notifies_the_bypassed_superiors(): void
    {
        Notification::fake();
        $director = User::factory()->create(['role' => 'dg', 'profile_status' => 'active']);
        ['manager' => $manager, 'agent' => $agent] = $this->teamWithTask();
        $chief = User::factory()->create([
            'role' => 'division_manager',
            'profile_status' => 'active',
            'department' => 'Finance',
            'division' => 'Comptabilité',
            'division_id' => $agent->division_id,
        ]);

        Sanctum::actingAs($director);
        $this->postJson('/api/tasks', [
            'title' => 'Audit interne',
            'description' => 'Contrôle des pièces comptables.',
            'assigned_to' => $agent->id,
        ])->assertCreated();

        Notification::assertSentTo($manager, TaskNotification::class);
        Notification::assertSentTo($chief, TaskNotification::class);
    }
}
