<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

#[Fillable(['slug', 'name', 'parent_slug', 'capability_role', 'position', 'is_system'])]
class OrganizationRole extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    public function parentRole(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_slug', 'slug');
    }

    public function childRoles(): Collection
    {
        $roles = self::query()->orderBy('position')->get();
        $descendants = collect();
        $parents = collect([$this->slug]);

        while ($parents->isNotEmpty()) {
            $children = $roles->whereIn('parent_slug', $parents)->whereNotIn('slug', $descendants->pluck('slug'));
            $descendants = $descendants->merge($children);
            $parents = $children->pluck('slug');
        }

        return $descendants;
    }

    public function supervisedRoleSlugs(): array
    {
        return $this->childRoles()->pluck('slug')->all();
    }
}
