<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $capabilityRole = $user->capabilityRole();
        $tasks = Task::query();

        if ($capabilityRole === 'manager') {
            $tasks->where('department', $user->department);
        } elseif ($capabilityRole === 'division_manager') {
            $tasks->where('department', $user->department)
                ->where(function ($query) use ($user): void {
                    $query->where('division', $user->division)
                        ->orWhere('status', 'paused');
                });
        } elseif ($capabilityRole === 'agent') {
            if ($user->isCustomSupervisor()) {
                $tasks->where('department', $user->department)->where('division', $user->division);
            } else {
                $tasks->where('assigned_to', $user->id);
            }
        }

        $counts = (clone $tasks)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $response = [
            'tasks_total' => (int) $counts->sum(),
            'tasks_by_status' => $counts,
            'tasks_due_soon' => (clone $tasks)
                ->whereNotNull('due_at')
                ->whereBetween('due_at', [now(), now()->addDays(7)])
                ->whereNotIn('status', ['completed', 'rejected'])
                ->count(),
            'tasks_overdue' => (clone $tasks)
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->whereIn('status', Task::OVERDUE_STATUSES)
                ->count(),
            'tasks_by_priority' => (clone $tasks)
                ->whereNotIn('status', ['completed', 'rejected'])
                ->selectRaw('priority, COUNT(*) as total')
                ->groupBy('priority')
                ->pluck('total', 'priority'),
        ];

        if ($user->role === 'admin') {
            $response['users_pending'] = User::where('profile_status', 'pending')->count();
            $response['users_total'] = User::count();
            $response['tasks_pending_review'] = Task::where('status', 'submitted')->count();
        } elseif (in_array($capabilityRole, ['dg', 'manager', 'division_manager'], true) || $user->isCustomSupervisor()) {
            $response['tasks_pending_review'] = (clone $tasks)->where('status', 'submitted')->count();
        }

        return response()->json(['data' => $response]);
    }
}
