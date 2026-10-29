<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrganizationRole;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\User;
use App\Notifications\TaskNotification;
use App\Support\PagePermissions;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->filteredTasks($request)->paginate(20)->withQueryString());
    }

    /**
     * Export CSV (lisible par Excel) des tâches visibles par l’utilisateur, avec les filtres appliqués.
     */
    public function export(Request $request): StreamedResponse
    {
        $tasks = $this->filteredTasks($request)->get();

        $statuses = [
            'assigned' => 'Nouvelle',
            'in_progress' => 'En cours',
            'submitted' => 'Terminée (à valider)',
            'needs_revision' => 'À refaire',
            'paused' => 'En attente de réaffectation',
            'completed' => 'Acceptée',
            'rejected' => 'Rejetée',
        ];
        $priorities = ['low' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute', 'urgent' => 'Urgente'];
        $safe = static function (mixed $value): string {
            $text = (string) $value;

            return $text !== '' && in_array($text[0], ['=', '+', '-', '@'], true) ? "'".$text : $text;
        };

        return response()->streamDownload(function () use ($tasks, $statuses, $priorities, $safe): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'N°', 'Titre', 'Priorité', 'Statut', 'En retard', 'Service', 'Division',
                'Donneur d’ordre', 'Assignée à', 'Échéance', 'Créée le',
            ], ';', '"', '\\');

            foreach ($tasks as $task) {
                fputcsv($handle, [
                    $task->id,
                    $safe($task->title),
                    $priorities[$task->priority] ?? $task->priority,
                    $statuses[$task->status] ?? $task->status,
                    $task->is_overdue ? 'Oui' : 'Non',
                    $safe($task->department),
                    $safe($task->division),
                    $safe($task->creator?->name),
                    $safe($task->assignee?->name),
                    $task->due_at?->format('d/m/Y H:i'),
                    $task->created_at?->format('d/m/Y H:i'),
                ], ';', '"', '\\');
            }

            fclose($handle);
        }, 'taches-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredTasks(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $user = $request->user();
        $capabilityRole = $user->capabilityRole();
        $query = Task::with([
            'creator:id,name,role',
            'creator.organizationRole:id,slug,capability_role',
            'assignee:id,name,role',
            'assignee.organizationRole:id,slug,capability_role',
        ])
            ->latest();

        if ($capabilityRole === 'manager') {
            $query->where('department', $user->department);
        } elseif ($capabilityRole === 'division_manager') {
            $query->where('department', $user->department)
                ->where(function ($query) use ($user): void {
                    $query->where('division', $user->division)
                        ->orWhere('status', 'paused');
                });
        } elseif ($capabilityRole === 'agent' && ! $user->isCustomSupervisor()) {
            $query->where('assigned_to', $user->id);
        } elseif (! in_array($capabilityRole, ['admin', 'dg', 'agent'], true)) {
            abort(403);
        } elseif ($user->isCustomSupervisor() && $capabilityRole === 'agent') {
            $query->where('department', $user->department)
                ->where('division', $user->division);
        }

        $filters = $request->validate([
            'priority' => ['sometimes', Rule::in(Task::PRIORITIES)],
            'overdue' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in([
                'assigned', 'in_progress', 'submitted', 'needs_revision', 'paused', 'completed', 'rejected',
            ])],
            'search' => ['sometimes', 'string', 'max:120'],
        ]);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($query) use ($search): void {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('division', 'like', "%{$search}%")
                    ->orWhereHas('creator', fn ($creator) => $creator->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('assignee', fn ($assignee) => $assignee->where('name', 'like', "%{$search}%"));
            });
        }

        if (isset($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if ($request->boolean('overdue')) {
            $query->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->whereIn('status', Task::OVERDUE_STATUSES);
        }

        return $query;
    }

    public function agents(Request $request): JsonResponse
    {
        $user = $request->user();
        $capabilityRole = $user->capabilityRole();
        $agentRoles = OrganizationRole::query()->where('capability_role', 'agent')->pluck('slug')->all();
        abort_unless(PagePermissions::canCreateTasks($user), 403);

        $query = User::query()->where('profile_status', 'active');

        if ($user->isCustomSupervisor()) {
            $query->whereIn('role', $user->supervisedRoleSlugs());
            if ($capabilityRole === 'manager') {
                $query->where('department', $user->department);
            } elseif (in_array($capabilityRole, ['division_manager', 'agent'], true)) {
                $query->where('department', $user->department)
                    ->where('division_id', $user->division_id);
            }
        } elseif ($user->role === 'dg') {
            $query->whereIn('role', OrganizationRole::query()
                ->whereIn('capability_role', ['manager', 'division_manager', 'agent'])
                ->pluck('slug'));
        } elseif ($user->role === 'admin') {
            $query->whereIn('role', OrganizationRole::query()
                ->whereIn('capability_role', ['dg', 'manager', 'division_manager', 'agent'])
                ->pluck('slug'));
        } elseif ($user->role === 'manager') {
            $query->whereIn('role', $agentRoles);
            $query->where('department', $user->department);
        } elseif ($user->role === 'division_manager') {
            $query->whereIn('role', $agentRoles);
            $query->where('department', $user->department)
                ->where('division', $user->division);
        } else {
            $query->where('role', 'agent');
        }

        return response()->json([
            'data' => $query->with('organizationRole:id,slug,capability_role')
                ->orderBy('role')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'department', 'division', 'role']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $creator = $request->user();
        $creatorRole = $creator->capabilityRole();
        abort_unless(PagePermissions::canCreateTasks($creator), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:10000'],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date', 'after_or_equal:today'],
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'priority' => ['sometimes', Rule::in(Task::PRIORITIES)],
        ]);

        $assigneeQuery = User::query()
            ->whereKey($data['assigned_to'])
            ->where('profile_status', 'active');

        if ($creator->isCustomSupervisor()) {
            $assigneeQuery->whereIn('role', $creator->supervisedRoleSlugs());
            if ($creatorRole === 'manager') {
                $assigneeQuery->where('department', $creator->department);
            } elseif (in_array($creatorRole, ['division_manager', 'agent'], true)) {
                $assigneeQuery->where('department', $creator->department)
                    ->where('division_id', $creator->division_id);
            } elseif ($creatorRole !== 'dg') {
                $assigneeQuery->whereRaw('1 = 0');
            }
        } elseif (in_array($creator->role, ['admin', 'dg'], true)) {
            $assigneeQuery->whereIn('role', OrganizationRole::query()
                ->whereIn('capability_role', $creator->role === 'admin'
                    ? ['dg', 'manager', 'division_manager', 'agent']
                    : ['manager', 'division_manager', 'agent'])
                ->pluck('slug'));
        } else {
            $assigneeQuery->whereIn('role', OrganizationRole::query()
                ->where('capability_role', 'agent')
                ->pluck('slug'))
                ->where('department', $creator->department);

            if ($creator->role === 'division_manager') {
                $assigneeQuery->where('division', $creator->division);
            }
        }
        $assignee = $assigneeQuery->first();

        if (! $assignee) {
            $message = match ($creatorRole) {
                'admin' => 'Choisissez un profil opérationnel actif.',
                'dg' => 'Choisissez un chef de service, un chef de division ou un agent actif.',
                'division_manager' => 'Choisissez un agent actif de votre division.',
                default => 'Choisissez un agent actif de votre service.',
            };

            throw ValidationException::withMessages([
                'assigned_to' => [$message],
            ]);
        }

        $task = DB::transaction(function () use ($data, $creator, $assignee): Task {
            $task = Task::create([
                ...$data,
                'department' => $assignee->department ?? $creator->department,
                'division' => $assignee->division,
                'created_by' => $creator->id,
                'status' => 'assigned',
            ]);

            $task->activities()->create([
                'user_id' => $creator->id,
                'body' => 'Tâche créée et affectée à '.$assignee->name.'.',
            ]);

            $assignee->notify(new TaskNotification(
                'Nouvelle tâche',
                'Une tâche vous a été confiée : '.$task->title,
                $task->id,
            ));
            $this->notifyBypassedSuperiors($task, $creator, $assignee);

            return $task;
        });

        return response()->json([
            'data' => ['task' => $this->loadTask($task)],
        ], 201);
    }

    /**
     * Saut de niveau : informe les supérieurs hiérarchiques contournés par l’attribution.
     */
    private function notifyBypassedSuperiors(Task $task, User $creator, User $assignee): void
    {
        $levels = ['dg' => 0, 'manager' => 1, 'division_manager' => 2, 'agent' => 3];
        $creatorLevel = $levels[$creator->capabilityRole()] ?? null;
        $assigneeLevel = $levels[$assignee->capabilityRole()] ?? null;

        if ($creatorLevel === null || $assigneeLevel === null || $assigneeLevel - $creatorLevel < 2) {
            return;
        }

        $bypassed = collect();

        foreach (['manager', 'division_manager'] as $role) {
            if ($levels[$role] <= $creatorLevel || $levels[$role] >= $assigneeLevel) {
                continue;
            }

            $query = User::query()
                ->whereIn('role', OrganizationRole::query()->where('capability_role', $role)->pluck('slug'))
                ->where('profile_status', 'active')
                ->where('department', $assignee->department);

            if ($role === 'division_manager') {
                $query->where('division_id', $assignee->division_id);
            }

            $bypassed = $bypassed->merge($query->get());
        }

        $bypassed->unique('id')->each(fn (User $superior) => $superior->notify(new TaskNotification(
            'Tâche attribuée en saut de niveau',
            $creator->name.' a confié « '.$task->title.' » à '.$assignee->name.' sans passer par votre niveau.',
            $task->id,
        )));
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorizeView($request->user(), $task);

        return response()->json(['data' => ['task' => $this->loadTask($task)]]);
    }

    public function reassignmentOptions(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->authorizeReassignment($user, $task);

        $roleSlugs = $user->isCustomSupervisor()
            ? $user->supervisedRoleSlugs()
            : OrganizationRole::query()->where('capability_role', 'agent')->pluck('slug')->all();
        $agents = User::query()
            ->whereIn('role', $roleSlugs)
            ->where('profile_status', 'active')
            ->where('department', $task->department);
        if ($user->capabilityRole() === 'division_manager'
            || ($user->isCustomSupervisor() && $user->capabilityRole() === 'agent')) {
            $agents->where('division', $user->division);
        }

        return response()->json([
            'data' => $agents->with('organizationRole:id,slug,capability_role')->orderBy('name')
                ->get(['id', 'name', 'email', 'department', 'division', 'role']),
        ]);
    }

    public function reassign(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $this->authorizeReassignment($user, $task);
        $data = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
        ]);

        $roleSlugs = $user->isCustomSupervisor()
            ? $user->supervisedRoleSlugs()
            : OrganizationRole::query()->where('capability_role', 'agent')->pluck('slug')->all();
        $agent = User::query()
            ->whereKey($data['assigned_to'])
            ->whereIn('role', $roleSlugs)
            ->where('profile_status', 'active')
            ->where('department', $task->department)
            ->when(
                $user->capabilityRole() === 'division_manager'
                    || ($user->isCustomSupervisor() && $user->capabilityRole() === 'agent'),
                fn ($query) => $query->where('division', $user->division),
            )
            ->first();

        if (! $agent) {
            throw ValidationException::withMessages([
                'assigned_to' => ['Choisissez un agent actif du même service.'],
            ]);
        }

        DB::transaction(function () use ($task, $user, $agent): void {
            $task->update([
                'assigned_to' => $agent->id,
                'created_by' => $user->id,
                'division' => $agent->division,
                'status' => 'assigned',
            ]);
            $task->activities()->create([
                'user_id' => $user->id,
                'body' => 'La tâche en pause a été réaffectée à '.$agent->name.' par '.$user->name.'.',
            ]);
            $agent->notify(new TaskNotification(
                'Tâche réaffectée',
                'Une tâche vous a été confiée : '.$task->title,
                $task->id,
            ));
        });

        return response()->json(['data' => ['task' => $this->loadTask($task->refresh())]]);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();
        $isAdmin = $actor->role === 'admin';
        $actorRole = $actor->capabilityRole();
        $isDgCreator = $actorRole === 'dg' && $task->created_by === $actor->id;
        abort_unless($this->canManageTask($actor, $task), 403);
        abort_if($task->status === 'paused', 422, 'Réaffectez cette tâche avant de la modifier.');

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'required', 'string', 'max:10000'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'assigned_to' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'created_by' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'priority' => ['sometimes', Rule::in(Task::PRIORITIES)],
        ]);

        if (! $isAdmin && ! $isDgCreator) {
            if (isset($data['created_by']) && (int) $data['created_by'] !== $actor->id) {
                abort(403);
            }
        }
        $managerId = ($isAdmin || $isDgCreator)
            ? ($data['created_by'] ?? $task->created_by)
            : $actor->id;
        if (! $isAdmin) {
            $data['created_by'] = $actor->id;
        }

        $managerRoles = OrganizationRole::query()
            ->whereIn('capability_role', ['manager', 'division_manager'])
            ->pluck('slug');
        $agentRoles = OrganizationRole::query()->where('capability_role', 'agent')->pluck('slug');
        $taskCreator = $isAdmin
            ? User::query()
                ->whereKey($managerId)
                ->where('profile_status', 'active')
                ->first()
            : $actor;
        $manager = $isAdmin ? $taskCreator : $actor;
        $dgTask = $isDgCreator || ($isAdmin && $taskCreator?->capabilityRole() === 'dg');
        $assignableRoles = $isAdmin || $isDgCreator
            ? $managerRoles->merge($agentRoles)->all()
            : ($actor->isCustomSupervisor() ? $actor->supervisedRoleSlugs() : $agentRoles->all());
        $agent = User::query()
            ->whereKey($data['assigned_to'] ?? $task->assigned_to)
            ->whereIn('role', $assignableRoles)
            ->where('profile_status', 'active')
            ->first();

        $taskDepartment = $dgTask ? $task->department : $manager?->department;
        if (! $taskDepartment) {
            throw ValidationException::withMessages([
                'created_by' => ['Choisissez un chef de service actif.'],
            ]);
        }
        if (! $dgTask && (! $manager || ! in_array($manager->role, $managerRoles->all(), true))) {
            throw ValidationException::withMessages([
                'created_by' => ['Choisissez un chef de service actif.'],
            ]);
        }

        if (
            ! $agent
            || $agent->department !== $taskDepartment
            || ($manager?->capabilityRole() === 'division_manager' && $agent->division !== $manager->division)
            || ($manager?->isCustomSupervisor()
                && $manager->capabilityRole() === 'agent'
                && $agent->division !== $manager->division)
        ) {
            throw ValidationException::withMessages([
                'assigned_to' => ['L’agent doit appartenir au périmètre du responsable choisi.'],
            ]);
        }

        $previousAssignee = $task->assignee;
        $assignmentChanged = (int) $task->assigned_to !== $agent->id;
        DB::transaction(function () use ($task, $actor, $isAdmin, $data, $taskDepartment, $agent, $previousAssignee, $assignmentChanged): void {
            $task->fill([
                ...$data,
                'department' => $taskDepartment,
                'division' => $agent->division,
            ])->save();
            $task->activities()->create([
                'user_id' => $actor->id,
                'body' => $isAdmin
                    ? 'Les informations de la tâche ont été mises à jour par l’administration.'
                    : 'Les informations de la tâche ont été mises à jour par '.$actor->name.'.',
            ]);

            if ($assignmentChanged && $previousAssignee && $previousAssignee->id !== $agent->id) {
                $previousAssignee->notify(new TaskNotification(
                    'Tâche réaffectée',
                    'La tâche « '.$task->title.' » ne vous est plus affectée.',
                    $task->id,
                ));
            }

            $agent->notify(new TaskNotification(
                $assignmentChanged ? 'Nouvelle tâche affectée' : 'Tâche modifiée',
                $assignmentChanged
                    ? ($isAdmin ? 'L’administration vous a affecté la tâche « ' : $actor->name.' vous a confié la tâche « ')
                        .$task->title.' ».'
                    : 'La tâche « '.$task->title.' » a été modifiée par '.($isAdmin ? 'l’administration' : $actor->name).'.',
                $task->id,
            ));
        });

        return response()->json(['data' => ['task' => $this->loadTask($task->refresh())]]);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->canManageTask($actor, $task), 403);

        $attachmentPaths = $task->activities()
            ->whereNotNull('attachment_path')
            ->pluck('attachment_path')
            ->all();

        DB::transaction(function () use ($task): void {
            $task->assignee?->notify(new TaskNotification(
                'Tâche supprimée',
                'La tâche « '.$task->title.' » a été supprimée. Vous n’y avez plus accès.',
                null,
            ));
            $task->delete();
        });

        if ($attachmentPaths && ! Storage::delete($attachmentPaths)) {
            Log::warning('Some task attachment files could not be deleted.', ['task_id' => $task->id]);
        }

        return response()->json(['message' => 'La tâche a été supprimée.']);
    }

    public function addActivity(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        abort_unless($task->created_by === $user->id || $task->assigned_to === $user->id, 403);
        abort_if(in_array($task->status, ['completed', 'rejected'], true), 422, 'Cette tâche est terminée.');

        $data = $request->validate([
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx'],
        ]);

        $activity = DB::transaction(function () use ($request, $task, $user, $data): TaskActivity {
            $attachment = $request->file('attachment');
            $activity = $task->activities()->create([
                'user_id' => $user->id,
                'body' => $data['body'] ?? '',
                'attachment_path' => null,
                'attachment_name' => $attachment?->getClientOriginalName(),
            ]);

            if ($attachment) {
                $path = $attachment->store('task-attachments');
                if (! $path) {
                    throw new \RuntimeException('The uploaded task attachment could not be stored.');
                }
                $activity->update(['attachment_path' => $path]);
            }

            $recipients = collect([$task->creator, $task->assignee])
                ->filter(fn ($recipient): bool => $recipient instanceof User && ! $recipient->trashed())
                ->reject(fn (User $recipient): bool => $recipient->is($user))
                ->keyBy('id');

            User::query()
                ->where('role', 'dg')
                ->where('profile_status', 'active')
                ->where('id', '<>', $user->id)
                ->get()
                ->each(fn (User $director): mixed => $recipients->put($director->id, $director));

            $recipients->each(fn (User $recipient) => $recipient->notify(new TaskNotification(
                'Nouveau message sur une tâche',
                $user->name.' a ajouté un message à « '.$task->title.' ».',
                $task->id,
            )));

            return $activity;
        });

        return response()->json([
            'data' => ['activity' => $this->presentActivity($activity->load('user.organizationRole:id,slug,capability_role'))],
        ], 201);
    }

    public function start(Request $request, Task $task): JsonResponse
    {
        $assignee = $request->user();
        abort_unless($task->assigned_to === $assignee->id, 403);

        $isResuming = in_array($task->status, ['needs_revision', 'rejected'], true);
        $canResumeRejectedTask = $task->status === 'rejected' && $task->rejected_by === null;
        if (! in_array($task->status, ['assigned', 'needs_revision'], true) && ! $canResumeRejectedTask) {
            abort(422, 'Cette tâche ne peut pas être démarrée dans son état actuel.');
        }

        DB::transaction(function () use ($task, $assignee, $isResuming): void {
            $task->update([
                'status' => 'in_progress',
                'rejected_by' => null,
                'rejected_reason' => null,
            ]);
            $task->activities()->create([
                'user_id' => $assignee->id,
                'body' => $isResuming
                    ? 'La tâche a été reprise.'
                    : 'La tâche a été démarrée.',
            ]);
            $task->creator->notify(new TaskNotification(
                'Tâche démarrée',
                $assignee->name.' a démarré « '.$task->title.' ».',
                $task->id,
            ));

            User::query()
                ->where('role', 'dg')
                ->where('profile_status', 'active')
                ->whereNotIn('id', [$assignee->id, $task->creator->id])
                ->get()
                ->each(fn (User $director) => $director->notify(new TaskNotification(
                    'Tâche démarrée',
                    $assignee->name.' a démarré « '.$task->title.' ».',
                    $task->id,
                )));
        });

        return response()->json(['data' => ['task' => $this->loadTask($task->refresh())]]);
    }

    public function submit(Request $request, Task $task): JsonResponse
    {
        $assignee = $request->user();
        abort_unless($task->assigned_to === $assignee->id, 403);

        if ($task->status !== 'in_progress') {
            abort(422, 'Cette tâche ne peut pas être soumise dans son état actuel.');
        }

        DB::transaction(function () use ($task, $assignee): void {
            $task->update([
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);
            $task->activities()->create([
                'user_id' => $assignee->id,
                'body' => 'La tâche a été envoyée à son responsable pour validation.',
            ]);
            $task->creator->notify(new TaskNotification(
                'Tâche à valider',
                $assignee->name.' a terminé « '.$task->title.' » et attend votre validation.',
                $task->id,
            ));

            User::query()
                ->where('role', 'dg')
                ->where('profile_status', 'active')
                ->where('id', '<>', $task->creator->id)
                ->get()
                ->each(fn (User $director) => $director->notify(new TaskNotification(
                    'Tâche à valider',
                    $assignee->name.' a terminé « '.$task->title.' » et attend une validation.',
                    $task->id,
                )));
        });

        return response()->json(['data' => ['task' => $this->loadTask($task->refresh())]]);
    }

    public function review(Request $request, Task $task): JsonResponse
    {
        $manager = $request->user();
        $managerRole = $manager->capabilityRole();
        abort_unless(
            $task->created_by === $manager->id
                || $managerRole === 'dg'
                || ($managerRole === 'manager' && $task->department === $manager->department)
                || ($managerRole === 'division_manager'
                    && $task->department === $manager->department
                    && $task->division === $manager->division)
                || ($manager->isCustomSupervisor() && $managerRole === 'agent'
                    && $task->department === $manager->department
                    && $task->division === $manager->division),
            403,
        );

        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'revision', 'rejected'])],
            'comment' => ['required_if:decision,revision,rejected', 'nullable', 'string', 'max:5000'],
        ]);

        if ($task->status !== 'submitted') {
            abort(422, 'Seules les tâches envoyées pour validation peuvent être examinées.');
        }

        $newStatus = match ($data['decision']) {
            'accepted' => 'completed',
            'revision' => 'needs_revision',
            'rejected' => 'rejected',
        };

        DB::transaction(function () use ($task, $manager, $data, $newStatus): void {
            $task->update([
                'status' => $newStatus,
                'completed_at' => $newStatus === 'completed' ? now() : null,
                'rejected_reason' => $newStatus === 'rejected' ? ($data['comment'] ?? null) : null,
            ]);
            $task->activities()->create([
                'user_id' => $manager->id,
                'body' => ($data['comment'] ?? null) ?: match ($newStatus) {
                    'completed' => 'Le travail a été validé.',
                    'needs_revision' => 'Des modifications sont demandées.',
                    default => 'La tâche a été refusée.',
                },
            ]);
            $task->assignee->notify(new TaskNotification(
                'Décision sur votre tâche',
                'Votre responsable a mis à jour « '.$task->title.' ».',
                $task->id,
            ));
        });

        return response()->json(['data' => ['task' => $this->loadTask($task->refresh())]]);
    }

    public function rejectByAdmin(Request $request, Task $task): JsonResponse
    {
        $admin = $request->user();
        abort_unless($admin->role === 'admin', 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        if (in_array($task->status, ['completed', 'rejected'], true)) {
            abort(422, 'Cette tâche est déjà clôturée.');
        }
        abort_if($task->status === 'paused', 422, 'Réaffectez cette tâche avant de la traiter.');

        DB::transaction(function () use ($task, $admin, $data): void {
            $task->update([
                'status' => 'rejected',
                'rejected_by' => $admin->id,
                'rejected_reason' => $data['reason'],
            ]);
            $task->activities()->create([
                'user_id' => $admin->id,
                'body' => 'Tâche rejetée par l’administration : '.$data['reason'],
            ]);
            $task->creator->notify(new TaskNotification(
                'Tâche rejetée par l’administration',
                'La tâche « '.$task->title.' » a été rejetée.',
                $task->id,
            ));
            $task->assignee->notify(new TaskNotification(
                'Tâche rejetée par l’administration',
                'La tâche « '.$task->title.' » a été arrêtée par l’administration.',
                $task->id,
            ));
        });

        return response()->json(['data' => ['task' => $this->loadTask($task->refresh())]]);
    }

    /**
     * Demande de report de date limite, soumise par le destinataire à son donneur d’ordre.
     */
    public function requestExtension(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        abort_unless($task->assigned_to === $user->id, 403);
        abort_unless(
            in_array($task->status, Task::OVERDUE_STATUSES, true),
            422,
            'Cette tâche ne peut plus faire l’objet d’une demande de report.',
        );
        abort_if($task->extension_status === 'pending', 422, 'Une demande de report est déjà en attente.');

        $data = $request->validate([
            'requested_due_at' => ['required', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $requestedAt = Carbon::parse($data['requested_due_at']);

        if ($task->due_at && $requestedAt->lessThanOrEqualTo($task->due_at)) {
            throw ValidationException::withMessages([
                'requested_due_at' => ['La nouvelle date doit être postérieure à la date limite actuelle.'],
            ]);
        }

        DB::transaction(function () use ($task, $user, $data, $requestedAt): void {
            $task->forceFill([
                'extension_status' => 'pending',
                'requested_due_at' => $requestedAt,
                'extension_reason' => $data['reason'],
                'extension_requested_at' => now(),
            ])->save();

            $task->activities()->create([
                'user_id' => $user->id,
                'body' => 'Demande de report de la date limite au '.$requestedAt->format('d/m/Y').' : '.$data['reason'],
            ]);

            $task->creator?->notify(new TaskNotification(
                'Demande de report',
                $user->name.' demande un report de la date limite de : '.$task->title,
                $task->id,
            ));
        });

        return response()->json(['data' => ['task' => $this->loadTask($task)]]);
    }

    /**
     * Décision du donneur d’ordre sur une demande de report (acceptation ou refus motivé).
     */
    public function decideExtension(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        abort_unless($task->created_by === $user->id, 403);
        abort_unless($task->extension_status === 'pending', 422, 'Aucune demande de report en attente.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['required_if:decision,rejected', 'nullable', 'string', 'max:2000'],
        ]);

        $approved = $data['decision'] === 'approved';

        DB::transaction(function () use ($task, $user, $data, $approved): void {
            $newDueAt = $task->requested_due_at;
            $updates = ['extension_status' => $approved ? 'approved' : 'rejected'];

            if ($approved) {
                $updates += [
                    'due_at' => $newDueAt,
                    'reminded_d2_at' => null,
                    'reminded_d0_at' => null,
                    'overdue_notified_at' => null,
                ];
            }

            $task->forceFill($updates)->save();

            $task->activities()->create([
                'user_id' => $user->id,
                'body' => $approved
                    ? 'Report accepté : nouvelle date limite le '.$newDueAt?->format('d/m/Y').'.'
                    : 'Report refusé : '.$data['comment'],
            ]);

            $task->assignee?->notify(new TaskNotification(
                $approved ? 'Report accepté' : 'Report refusé',
                ($approved ? 'Votre demande de report a été acceptée pour : ' : 'Votre demande de report a été refusée pour : ').$task->title,
                $task->id,
            ));
        });

        return response()->json(['data' => ['task' => $this->loadTask($task)]]);
    }

    public function downloadAttachment(Request $request, Task $task, TaskActivity $activity): StreamedResponse
    {
        $this->authorizeView($request->user(), $task);
        abort_unless($activity->task_id === $task->id && $activity->attachment_path, 404);
        abort_unless(Storage::disk('local')->exists($activity->attachment_path), 404);

        return Storage::disk('local')->download($activity->attachment_path, $activity->attachment_name);
    }

    private function authorizeView(User $user, Task $task): void
    {
        abort_unless(
            in_array($user->capabilityRole(), ['admin', 'dg'], true)
                || ($user->capabilityRole() === 'manager' && $task->department === $user->department
                    && ($task->assigned_to === $user->id || $user->isCustomSupervisor() || $task->created_by === $user->id))
                || ($user->capabilityRole() === 'division_manager'
                    && $task->department === $user->department
                    && ($task->division === $user->division || $task->status === 'paused')
                    && ($task->assigned_to === $user->id || $user->isCustomSupervisor() || $task->created_by === $user->id))
                || ($user->capabilityRole() === 'agent'
                    && ($user->isCustomSupervisor()
                        ? $task->department === $user->department && $task->division === $user->division
                        : $task->assigned_to === $user->id)),
            403,
        );
    }

    private function canManageTask(User $actor, Task $task): bool
    {
        $actorRole = $actor->capabilityRole();
        $canManage = in_array($actorRole, ['manager', 'division_manager'], true)
            || $actor->isCustomSupervisor();

        return $actor->role === 'admin'
            || ($actorRole === 'dg' && $task->created_by === $actor->id)
            || ($canManage
                && $task->department === $actor->department
                && ($actorRole !== 'division_manager' || $task->division === $actor->division)
                && ($actorRole !== 'agent' || ! $actor->isCustomSupervisor() || $task->division === $actor->division));
    }

    private function authorizeReassignment(User $user, Task $task): void
    {
        abort_unless(
            $task->status === 'paused'
                && (in_array($user->capabilityRole(), ['dg', 'manager', 'division_manager'], true)
                    || $user->isCustomSupervisor())
                && ($user->capabilityRole() === 'dg' || $task->department === $user->department)
                && ($user->capabilityRole() !== 'agent'
                    || ! $user->isCustomSupervisor()
                    || $task->division === $user->division),
            403,
        );
    }

    private function loadTask(Task $task): Task
    {
        $task->load([
            'creator:id,name,role',
            'creator.organizationRole:id,slug,capability_role',
            'assignee:id,name,role',
            'assignee.organizationRole:id,slug,capability_role',
            'rejectedBy:id,name',
            'activities.user:id,name,role',
            'activities.user.organizationRole:id,slug,capability_role',
        ]);

        $task->activities->each(function (TaskActivity $activity): void {
            $activity->setAttribute(
                'attachment_url',
                $activity->attachment_path
                    ? route('tasks.activities.attachment', [$activity->task_id, $activity->id], false)
                    : null,
            );
            $activity->makeHidden('attachment_path');
        });

        return $task;
    }

    private function presentActivity(TaskActivity $activity): TaskActivity
    {
        $activity->setAttribute(
            'attachment_url',
            $activity->attachment_path
                ? route('tasks.activities.attachment', [$activity->task_id, $activity->id], false)
                : null,
        );
        $activity->makeHidden('attachment_path');

        return $activity;
    }
}
