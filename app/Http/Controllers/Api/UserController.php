<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\OrganizationRole;
use App\Models\User;
use App\Notifications\TaskNotification;
use App\Support\PagePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $filters = $request->validate([
            'role' => ['sometimes', 'string', Rule::exists('organization_roles', 'slug')],
            'search' => ['sometimes', 'string', 'max:120'],
        ]);

        $query = User::query()
            ->select(['id', 'name', 'email', 'role', 'department', 'division', 'division_id', 'phone', 'profile_photo_path', 'profile_status', 'allowed_pages', 'created_at'])
            ->with(['divisionRecord:id,name,department', 'organizationRole:id,slug,capability_role'])
            ->when($request->user()->role === 'admin', fn ($query) => $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$request->user()->id]))
            ->latest();

        if (isset($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('division', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate(5)->withQueryString());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => [
                'required',
                'string',
                Rule::exists('organization_roles', 'slug')->where(
                    fn ($query) => $query->where('slug', '<>', 'admin'),
                ),
            ],
            'department' => ['nullable', 'string', 'max:120'],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'phone' => ['nullable', 'string', 'max:30'],
            'allowed_pages' => ['required', 'array', 'min:1'],
            'allowed_pages.*' => [
                'required',
                'string',
                Rule::in($this->availablePagesForRole((string) $request->input('role'))),
            ],
        ]);

        $data['email'] = strtolower(trim($data['email']));
        if (in_array($data['email'], array_column(config('initial_role_accounts'), 'email'), true)) {
            throw ValidationException::withMessages([
                'email' => ['Cette adresse est réservée à un compte initial de direction.'],
            ]);
        }

        $data = $this->applyOrganizationScope($data);
        $data['profile_status'] = 'active';

        $user = User::query()->create($data);

        return response()->json(['data' => ['user' => $user->load('divisionRecord')]], 201);
    }

    public function team(Request $request): JsonResponse
    {
        $director = $request->user();
        abort_unless(in_array($director->role, ['admin', 'dg'], true), 403);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
        ]);

        $visibleRoles = $director->isCustomSupervisor()
            ? $director->supervisedRoleSlugs()
            : OrganizationRole::query()
                ->whereIn('capability_role', ['manager', 'division_manager', 'agent'])
                ->pluck('slug')
                ->all();
        $query = User::query()
            ->whereIn('role', $visibleRoles)
            ->where('profile_status', 'active')
            ->whereNotNull('department')
            ->where('department', '<>', '')
            ->select(['id', 'name', 'email', 'role', 'department', 'division', 'division_id', 'profile_status', 'created_at'])
            ->with(['divisionRecord:id,name,department', 'organizationRole:id,slug,capability_role'])
            ->orderBy('role')
            ->orderBy('name');

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('division', 'like', "%{$search}%");
            });
        }

        $members = $query->get();

        return response()->json([
            'data' => $members,
            'total' => $members->count(),
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless(in_array($request->user()->capabilityRole(), ['admin', 'dg'], true), 403);

        $user->load(['divisionRecord:id,name,department', 'organizationRole:id,slug,capability_role']);
        $teamMembers = collect();

        if ($user->capabilityRole() === 'manager') {
            $teamMembers = User::query()
                ->whereIn('role', $user->isCustomSupervisor() ? $user->supervisedRoleSlugs() : ['agent'])
                ->where('department', $user->department)
                ->with('organizationRole:id,slug,capability_role')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'department', 'division', 'division_id', 'profile_status']);
        } elseif ($user->capabilityRole() === 'division_manager' && $user->division_id) {
            $teamMembers = User::query()
                ->whereIn('role', $user->isCustomSupervisor() ? $user->supervisedRoleSlugs() : ['agent'])
                ->where('division_id', $user->division_id)
                ->with('organizationRole:id,slug,capability_role')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'department', 'division', 'division_id', 'profile_status']);
        }

        return response()->json([
            'data' => [
                'user' => $user,
                'team_members' => $teamMembers,
            ],
        ]);
    }

    public function managers(): JsonResponse
    {
        $actor = request()->user();
        abort_unless(
            $actor->role === 'admin'
                || in_array($actor->capabilityRole(), ['manager', 'division_manager'], true)
                || $actor->isCustomSupervisor(),
            403,
        );

        if ($actor->role !== 'admin') {
            return response()->json(['data' => [$actor->load('organizationRole')]]);
        }

        $managerRoles = OrganizationRole::query()
            ->whereIn('capability_role', ['manager', 'division_manager'])
            ->pluck('slug');

        return response()->json([
            'data' => User::query()
                ->whereIn('role', $managerRoles)
                ->where('profile_status', 'active')
                ->orderBy('name')
                ->with('organizationRole:id,slug,capability_role')
                ->get(['id', 'name', 'email', 'department', 'division', 'division_id', 'role']),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_if($request->exists('email'), 422, 'Les adresses e-mail des comptes ne peuvent pas être modifiées.');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'role' => [
                'sometimes',
                'string',
                Rule::exists('organization_roles', 'slug')->where(
                    fn ($query) => $query->where('slug', '<>', 'admin'),
                ),
            ],
            'profile_status' => ['sometimes', Rule::in(['pending', 'active', 'rejected', 'suspended'])],
            'department' => ['sometimes', 'nullable', 'string', 'max:120'],
            'division_id' => ['sometimes', 'nullable', 'integer', 'exists:divisions,id'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'allowed_pages' => ['sometimes', 'array', 'min:1'],
            'allowed_pages.*' => [
                'required',
                'string',
                Rule::in($this->availablePagesForRole((string) $request->input('role', $user->role))),
            ],
        ]);

        $targetRole = $data['role'] ?? $user->role;
        $targetPages = $data['allowed_pages'] ?? $user->allowed_pages;
        if (is_array($targetPages)) {
            if (array_diff($targetPages, $this->availablePagesForRole($targetRole)) !== []) {
                throw ValidationException::withMessages([
                    'allowed_pages' => ['Une ou plusieurs pages ne sont pas disponibles pour ce rôle.'],
                ]);
            }
        }

        if ($user->is($request->user())) {
            if (isset($data['profile_status']) && $data['profile_status'] !== 'active') {
                abort(422, 'Vous ne pouvez pas suspendre ou désactiver votre propre compte.');
            }
        }

        $data = $this->applyOrganizationScope($data, $user);
        $user->fill($data)->save();

        if (($data['profile_status'] ?? null) === 'suspended') {
            $user->tokens()->delete();
        }

        if (array_key_exists('profile_status', $data) && in_array($data['profile_status'], ['active', 'rejected'], true)) {
            $user->notify(new TaskNotification(
                'Mise à jour de votre compte',
                $data['profile_status'] === 'active'
                    ? 'Votre compte est actif. Vous pouvez maintenant vous connecter.'
                    : 'Votre demande de compte a été refusée.',
                null,
            ));
        }

        return response()->json(['data' => ['user' => $user->fresh()->load('divisionRecord')]]);
    }

    /**
     * Apply the IT-managed division catalog to a user's organization fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyOrganizationScope(array $data, ?User $user = null): array
    {
        if ($user
            && ! array_key_exists('role', $data)
            && ! array_key_exists('department', $data)
            && ! array_key_exists('division_id', $data)
            && ! ($user->role === 'division_manager' && ($data['profile_status'] ?? null) === 'active')) {
            return $data;
        }

        $role = $data['role'] ?? $user?->role;
        if ($role === null) {
            return $data;
        }

        $capabilityRole = OrganizationRole::query()->where('slug', $role)->value('capability_role') ?? $role;

        if (in_array($capabilityRole, ['dg', 'admin'], true)) {
            $data['department'] = null;
            $data['division'] = null;
            $data['division_id'] = null;

            return $data;
        }

        if ($capabilityRole === 'manager') {
            $department = $data['department'] ?? $user?->department;
            if (! is_string($department) || trim($department) === '') {
                throw ValidationException::withMessages([
                    'department' => ['Un service doit être renseigné pour ce chef de service.'],
                ]);
            }

            $data['department'] = trim($department);
            if (! Division::query()->where('department', $data['department'])->exists()) {
                throw ValidationException::withMessages([
                    'department' => ['Sélectionnez un service existant dans l’organisation.'],
                ]);
            }
            $data['division'] = null;
            $data['division_id'] = null;

            return $data;
        }

        $divisionId = $data['division_id'] ?? $user?->division_id;
        if ($divisionId === null) {
            throw ValidationException::withMessages([
                'division_id' => ['Sélectionnez une division gérée par l’administration IT.'],
            ]);
        }

        $division = Division::query()->find($divisionId);
        if (! $division) {
            throw ValidationException::withMessages([
                'division_id' => ['La division sélectionnée n’existe pas.'],
            ]);
        }

        if ($role === 'division_manager' && ($data['profile_status'] ?? $user?->profile_status ?? 'active') === 'active') {
            $existingManager = User::query()
                ->where('role', 'division_manager')
                ->where('profile_status', 'active')
                ->where('division_id', $division->id)
                ->when($user, fn ($query) => $query->where('id', '!=', $user->id))
                ->exists();

            if ($existingManager) {
                throw ValidationException::withMessages([
                    'division_id' => ['Cette division a déjà un chef de division actif.'],
                ]);
            }
        }

        $data['division_id'] = $division->id;
        $data['department'] = $division->department;
        $data['division'] = $division->name;

        return $data;
    }

    /**
     * @return list<string>
     */
    private function availablePagesForRole(string $roleSlug): array
    {
        $role = OrganizationRole::query()->where('slug', $roleSlug)->first();

        if (! $role) {
            return [];
        }

        return PagePermissions::availableForRole($role->capability_role, ! $role->is_system);
    }
}
