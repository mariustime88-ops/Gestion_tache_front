<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'directiongeneral@gmail.com')
            ->where('role', 'admin')
            ->update(['role' => 'dg']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', 'directiongeneral@gmail.com')
            ->where('role', 'dg')
            ->update(['role' => 'admin']);
    }
};
