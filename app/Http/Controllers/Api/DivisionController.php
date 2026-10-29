<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\DivisionDeletionLog;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DivisionController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => Division::query()
                ->orderBy('department')
                ->orderBy('name')
                ->get(['id', 'department', 'name']),
        ]);
    }

    public function index(): JsonResponse
    {
        abort_unless(in_array(request()->user()->capabilityRole(), ['admin', 'dg'], true), 403);

        return response()->json([
            'data' => Division::query()
                ->withCount('members')
                ->withCount('activeMembers')
                ->with('manager:id,name,email,role,division_id,profile_status')
                ->orderBy('department')
                ->orderBy('name')
                ->get(),
            'deletion_history' => DivisionDeletionLog::query()
                ->with('deletedBy:id,name')
                ->orderByDesc('deleted_at')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $data = $request->validate([
            'department' => ['required', 'string', 'max:120'],
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('divisions', 'name')->where('department', $request->input('department')),
            ],
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('role', 'division_manager')->where('profile_status', 'active'),
            ],
        ]);

        $division = DB::transaction(function () use ($data): Division {
            $division = Division::query()->create([
                'department' => $data['department'],
                'name' => $data['name'],
            ]);

            if ($managerId = $data['manager_id'] ?? null) {
                $this->assignManager($division, (int) $managerId);
            }

            return $division;
        });

        return response()->json([
            'data' => [
                'division' => $division->fresh()
                    ->loadCount('members')
                    ->loadCount('activeMembers')
                    ->load('manager:id,name,email,role,division_id,profile_status'),
            ],
        ], 201);
    }

    public function update(Request $request, Division $division): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $data = $request->validate([
            'department' => ['required', 'string', 'max:120'],
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('divisions', 'name')
                    ->where('department', $request->input('department'))
                    ->ignore($division->id),
            ],
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('role', 'division_manager')->where('profile_status', 'active'),
            ],
        ]);

        DB::transaction(function () use ($division, $data): void {
            $currentManager = $division->manager;
            $division->update([
                'department' => $data['department'],
                'name' => $data['name'],
            ]);
            $division->members()->update([
                'department' => $data['department'],
                'division' => $data['name'],
            ]);

            if (array_key_exists('manager_id', $data)) {
                $managerId = $data['manager_id'];
                if ($currentManager && (int) $currentManager->id !== (int) $managerId) {
                    $currentManager->update(['role' => 'agent']);
                }
                if ($managerId) {
                    $this->assignManager($division, (int) $managerId);
                }
            }
        });

        return response()->json([
            'data' => [
                'division' => $division->fresh()
                    ->loadCount('members')
                    ->loadCount('activeMembers')
                    ->load('manager:id,name,email,role,division_id,profile_status'),
            ],
        ]);
    }

    public function destroy(Request $request, Division $division): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $administrator = $request->user();
        DB::transaction(function () use ($division, $administrator): void {
            $members = $division->members()->get();

            $openTasks = Task::query()
                ->where('department', $division->department)
                ->where('division', $division->name)
                ->whereNotIn('status', ['completed', 'rejected'])
                ->with('assignee:id,name')
                ->get();
            DivisionDeletionLog::query()->create([
                'name' => $division->name,
                'department' => $division->department,
                'members_count' => $members->count(),
                'paused_tasks_count' => $openTasks->count(),
                'deleted_by_id' => $administrator->id,
                'deleted_at' => now(),
            ]);

            foreach ($openTasks as $task) {
                $previousAssignee = $task->assignee?->name;
                $task->update(['status' => 'paused', 'assigned_to' => null]);
                $task->activities()->create([
                    'user_id' => $administrator->id,
                    'body' => 'Tâche mise en pause après la suppression de la division « '.$division->name.' ».'
                        .($previousAssignee ? ' Ancien responsable : '.$previousAssignee.'.' : ''),
                ]);
            }

            foreach ($members as $member) {
                $member->tokens()->delete();
                $member->notifications()->delete();
                $member->update(['division_id' => null]);
                $member->delete();
            }

            $division->delete();
        });

        return response()->json(['message' => 'La division et ses profils ont été supprimés. Les tâches en cours sont en pause et peuvent être réaffectées.']);
    }

    private function assignManager(Division $division, int $managerId): void
    {
        $manager = User::query()->findOrFail($managerId);
        $manager->update([
            'department' => $division->department,
            'division' => $division->name,
            'division_id' => $division->id,
        ]);
    }
}
