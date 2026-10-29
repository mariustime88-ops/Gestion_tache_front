<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\User;

class UserObserver
{
    private const IGNORED_FIELDS = ['updated_at', 'remember_token'];

    public function created(User $user): void
    {
        AuditLog::record('user.created', $user, 'Création du compte de '.$user->name.'.', [
            'role' => ['old' => null, 'new' => $user->role],
        ]);
    }

    public function updated(User $user): void
    {
        $changes = [];

        foreach ($user->getChanges() as $field => $newValue) {
            if (in_array($field, self::IGNORED_FIELDS, true)) {
                continue;
            }

            $isSecret = $field === 'password';
            $changes[$field] = [
                'old' => $isSecret ? '***' : $user->getOriginal($field),
                'new' => $isSecret ? '***' : $newValue,
            ];
        }

        if ($changes === []) {
            return;
        }

        AuditLog::record('user.updated', $user, 'Modification du compte de '.$user->name.'.', $changes);
    }

    public function deleted(User $user): void
    {
        AuditLog::record('user.deactivated', $user, 'Désactivation du compte de '.$user->name.'.');
    }

    public function restored(User $user): void
    {
        AuditLog::record('user.restored', $user, 'Réactivation du compte de '.$user->name.'.');
    }
}
