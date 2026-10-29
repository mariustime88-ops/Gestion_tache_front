<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->join('organization_roles', 'users.role', '=', 'organization_roles.slug')
            ->where('organization_roles.capability_role', 'dg')
            ->whereNotNull('users.allowed_pages')
            ->select(['users.id', 'users.allowed_pages'])
            ->orderBy('users.id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $pages = json_decode($user->allowed_pages, true);
                    if (! is_array($pages) || in_array('audit', $pages, true)) {
                        continue;
                    }

                    $pages[] = 'audit';
                    DB::table('users')->where('id', $user->id)->update([
                        'allowed_pages' => json_encode(array_values($pages)),
                    ]);
                }
            }, 'users.id', 'id');
    }

    public function down(): void
    {
        DB::table('users')
            ->join('organization_roles', 'users.role', '=', 'organization_roles.slug')
            ->where('organization_roles.capability_role', 'dg')
            ->whereNotNull('allowed_pages')
            ->select(['users.id', 'users.allowed_pages'])
            ->orderBy('users.id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $pages = json_decode($user->allowed_pages, true);
                    if (! is_array($pages) || ! in_array('audit', $pages, true)) {
                        continue;
                    }

                    DB::table('users')->where('id', $user->id)->update([
                        'allowed_pages' => json_encode(array_values(array_diff($pages, ['audit']))),
                    ]);
                }
            }, 'users.id', 'id');
    }
};
