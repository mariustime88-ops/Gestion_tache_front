<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'department', 'members_count', 'paused_tasks_count', 'deleted_by_id', 'deleted_at'])]
class DivisionDeletionLog extends Model
{
    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_id')->withTrashed();
    }
}
