<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'department', 'division', 'division_id', 'phone', 'profile_status', 'allowed_pages'])]
#[Hidden(['password', 'remember_token', 'profile_photo_path', 'organization_role'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $appends = ['profile_photo_url', 'capability_role'];

    public function organizationRole(): BelongsTo
    {
        return $this->belongsTo(OrganizationRole::class, 'role', 'slug');
    }

    public function capabilityRole(): string
    {
        return $this->organizationRole?->capability_role ?? $this->role;
    }

    protected function getCapabilityRoleAttribute(): ?string
    {
        if (! isset($this->attributes['role']) || ! is_string($this->attributes['role'])) {
            return null;
        }

        return $this->capabilityRole();
    }

    public function isCustomSupervisor(): bool
    {
        return $this->organizationRole !== null && ! $this->organizationRole->is_system;
    }

    public function supervisedRoleSlugs(): array
    {
        return $this->organizationRole?->supervisedRoleSlugs() ?? [];
    }

    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => $this->profile_photo_path
                ? Storage::disk('public')->url($this->profile_photo_path)
                : null,
        );
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    public function divisionRecord(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function taskActivities(): HasMany
    {
        return $this->hasMany(TaskActivity::class);
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    public function projectApplications(): HasMany
    {
        return $this->hasMany(ProjectApplication::class, 'applicant_id');
    }

    public function projectMessages(): HasMany
    {
        return $this->hasMany(ProjectMessage::class, 'sender_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'allowed_pages' => 'array',
        ];
    }
}
