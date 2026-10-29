<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['department', 'name'])]
class Division extends Model
{
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('profile_status', 'active');
    }

    public function manager(): HasOne
    {
        return $this->hasOne(User::class)->where('role', 'division_manager');
    }
}
