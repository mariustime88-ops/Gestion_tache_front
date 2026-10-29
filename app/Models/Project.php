<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'summary',
    'description',
    'organization_name',
    'department',
    'owner_id',
    'deadline',
    'status',
])]
class Project extends Model
{
    protected function casts(): array
    {
        return ['deadline' => 'date'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ProjectApplication::class);
    }
}
