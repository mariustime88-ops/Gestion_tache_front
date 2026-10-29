<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleSlugs = DB::table('organization_roles')
            ->whereIn('capability_role', ['dg', 'manager', 'division_manager'])
            ->orWhere('is_system', false)
            ->pluck('slug')
            ->all();

        DB::table('users')
            ->whereIn('role', $roleSlugs)
            ->whereNotNull('allowed_pages')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $pages = json_decode($user->allowed_pages, true);

                    if (! is_array($pages) || ! in_array('tasks', $pages, true) || in_array('create_tasks', $pages, true)) {
                        continue;
                    }

                    $pages[] = 'create_tasks';
                    DB::table('users')->where('id', $user->id)->update([
                        'allowed_pages' => json_encode($pages),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('users')
            ->whereNotNull('allowed_pages')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $pages = json_decode($user->allowed_pages, true);

                    if (! is_array($pages) || ! in_array('create_tasks', $pages, true)) {
                        continue;
                    }

                    DB::table('users')->where('id', $user->id)->update([
                        'allowed_pages' => json_encode(array_values(array_diff($pages, ['create_tasks']))),
                    ]);
                }
            });
    }
};
