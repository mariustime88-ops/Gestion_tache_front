<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $capabilityRole = $user->capabilityRole();
        $query = Project::with(['owner:id,name,department', 'owner.organizationRole:id,slug,capability_role'])->latest();

        if ($capabilityRole === 'manager') {
            $query->where('owner_id', $user->id)->withCount('applications');
        } elseif ($capabilityRole === 'agent') {
            $query->where('status', 'open')
                ->where(fn (Builder $query) => $query->whereNull('deadline')->orWhereDate('deadline', '>=', today()));
        } elseif ($user->role === 'admin') {
            $query->withCount('applications');
        } else {
            abort(403);
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $owner = $request->user();
        abort_unless($owner->capabilityRole() === 'manager' && $owner->profile_status === 'active', 403);
        abort_if(! $owner->department, 422, 'Un service doit être défini sur votre profil avant de publier un projet.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'summary' => ['required', 'string', 'max:280'],
            'description' => ['required', 'string', 'max:10000'],
            'organization_name' => ['required', 'string', 'max:180'],
            'deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'status' => ['sometimes', Rule::in(['draft', 'open'])],
        ]);

        $project = Project::create([
            ...$data,
            'owner_id' => $owner->id,
            'department' => $owner->department,
            'status' => $data['status'] ?? 'open',
        ]);

        return response()->json([
            'data' => ['project' => $project->load('owner:id,name,department')],
        ], 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $project);

        $project->load(['owner:id,name,department', 'owner.organizationRole:id,slug,capability_role']);
        $project->load([
            'applications' => function ($query) use ($user): void {
                if ($user->capabilityRole() === 'agent') {
                    $query->where('applicant_id', $user->id);
                }
                $query->with([
                    'applicant:id,name,email,department',
                    'applicant.organizationRole:id,slug,capability_role',
                    'messages.sender:id,name',
                    'messages.sender.organizationRole:id,slug,capability_role',
                ])->latest();
            },
        ]);

        return response()->json(['data' => ['project' => $project]]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->role === 'admin' || ($user->capabilityRole() === 'manager' && $project->owner_id === $user->id),
            403,
        );

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:180'],
            'summary' => ['sometimes', 'required', 'string', 'max:280'],
            'description' => ['sometimes', 'required', 'string', 'max:10000'],
            'organization_name' => ['sometimes', 'required', 'string', 'max:180'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(['draft', 'open', 'closed'])],
        ]);

        $project->fill($data)->save();

        return response()->json([
            'data' => ['project' => $project->refresh()->load('owner:id,name,department')],
        ]);
    }

    private function authorizeView(User $user, Project $project): void
    {
        if ($user->role === 'admin' || ($user->capabilityRole() === 'manager' && $project->owner_id === $user->id)) {
            return;
        }

        abort_unless(
            $user->capabilityRole() === 'agent'
                && (
                    ($project->status === 'open'
                        && (! $project->deadline || $project->deadline->isToday() || $project->deadline->isFuture()))
                    || $project->applications()->where('applicant_id', $user->id)->exists()
                ),
            404,
        );
    }
}
