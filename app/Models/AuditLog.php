<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Journal d’audit : les lignes sont en écriture seule (ni modification, ni suppression).
 */
#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'description', 'changes', 'ip_address'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Enregistre une action avec son auteur, sa date et les valeurs modifiées.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function record(string $action, ?Model $subject, string $description, array $changes = []): self
    {
        return self::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'changes' => $changes === [] ? null : $changes,
            'ip_address' => request()?->ip(),
        ]);
    }
}
