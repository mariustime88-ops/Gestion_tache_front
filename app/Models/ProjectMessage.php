<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_application_id', 'sender_id', 'body'])]
class ProjectMessage extends Model
{
    public function application(): BelongsTo
    {
        return $this->belongsTo(ProjectApplication::class, 'project_application_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
