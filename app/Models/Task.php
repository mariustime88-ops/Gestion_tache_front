<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'description',
    'instructions',
    'department',
    'division',
    'due_at',
    'created_by',
    'assigned_to',
    'rejected_by',
    'status',
    'priority',
    'rejected_reason',
    'submitted_at',
    'completed_at',
])]
class Task extends Model
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** Statuts pour lesquels le dépassement de la date limite rend la tâche « en retard ». */
    public const OVERDUE_STATUSES = ['assigned', 'in_progress', 'needs_revision'];

    protected $appends = ['is_overdue'];

    protected function isOverdue(): Attribute
    {
        return Attribute::get(fn (): bool => $this->due_at !== null
            && $this->due_at->isPast()
            && in_array($this->status, self::OVERDUE_STATUSES, true));
    }

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'requested_due_at' => 'datetime',
            'extension_requested_at' => 'datetime',
            'reminded_d2_at' => 'datetime',
            'reminded_d0_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'review_reminded_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withTrashed();
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by')->withTrashed();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->latest();
    }
}
