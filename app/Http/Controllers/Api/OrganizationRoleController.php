<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrganizationRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationRoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => OrganizationRole::query()
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'slug', 'name', 'parent_slug', 'capability_role', 'position', 'is_system']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:organization_roles,name'],
            'parent_slug' => [
                'required',
                'string',
                Rule::exists('organization_roles', 'slug')->where(
                    fn ($query) => $query->where('slug', '<>', 'admin'),
                ),
            ],
        ]);

        $role = DB::transaction(function () use ($data): OrganizationRole {
            $insertAbove = OrganizationRole::query()
                ->where('slug', $data['parent_slug'])
                ->lockForUpdate()
                ->firstOrFail();
            $baseSlug = Str::slug($data['name']) ?: 'role';
            $slug = $baseSlug;
            $suffix = 2;
            while (OrganizationRole::query()->where('slug', $slug)->exists()) {
                $suffixText = '-'.$suffix++;
                $slug = Str::substr($baseSlug, 0, 120 - Str::length($suffixText)).$suffixText;
            }

            $role = OrganizationRole::query()->create([
                'slug' => $slug,
                'name' => trim($data['name']),
                'parent_slug' => $insertAbove->parent_slug,
                'capability_role' => $insertAbove->capability_role,
                'position' => max(1, $insertAbove->position - 1),
            ]);

            $insertAbove->update([
                'parent_slug' => $role->slug,
            ]);

            return $role;
        });

        return response()->json(['data' => ['role' => $role]], 201);
    }
}
