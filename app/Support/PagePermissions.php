<?php

namespace App\Support;

use App\Models\User;

final class PagePermissions
{
    private const CREATE_TASKS = 'create_tasks';

    private const PAGES = [
        'dashboard' => ['dg', 'manager', 'division_manager', 'agent'],
        'tasks' => ['dg', 'manager', 'division_manager', 'agent'],
        self::CREATE_TASKS => ['dg', 'manager', 'division_manager'],
        'team' => ['dg'],
        'profile' => ['dg', 'manager', 'division_manager', 'agent'],
        'audit' => ['dg'],
    ];

    /**
     * @return list<string>
     */
    public static function availableForRole(string $capabilityRole, bool $isCustomSupervisor = false): array
    {
        $available = array_keys(array_filter(
            self::PAGES,
            fn (array $roles): bool => in_array($capabilityRole, $roles, true),
        ));

        if ($isCustomSupervisor && ! in_array(self::CREATE_TASKS, $available, true)) {
            $available[] = self::CREATE_TASKS;
        }

        return $available;
    }

    public static function canCreateTasks(User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        $capabilityRole = $user->capabilityRole();
        $roleCanCreate = in_array($capabilityRole, ['dg', 'manager', 'division_manager'], true)
            || $user->isCustomSupervisor();

        if (! $roleCanCreate) {
            return false;
        }

        return is_array($user->allowed_pages)
            ? in_array(self::CREATE_TASKS, $user->allowed_pages, true)
            : true;
    }

    public static function allows(User $user, string $page): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if (is_array($user->allowed_pages)) {
            return in_array($page, $user->allowed_pages, true);
        }

        return in_array($page, self::availableForRole($user->capabilityRole()), true);
    }
}
