<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('name', 120)->unique();
            $table->string('parent_slug', 120)->nullable()->index();
            $table->string('capability_role', 40);
            $table->unsignedInteger('position');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        DB::table('organization_roles')->insert([
            ['slug' => 'admin', 'name' => 'Administration informatique', 'parent_slug' => null, 'capability_role' => 'admin', 'position' => 0, 'is_system' => true],
            ['slug' => 'dg', 'name' => 'Directeur général', 'parent_slug' => 'admin', 'capability_role' => 'dg', 'position' => 10, 'is_system' => true],
            ['slug' => 'manager', 'name' => 'Chef de service', 'parent_slug' => 'dg', 'capability_role' => 'manager', 'position' => 20, 'is_system' => true],
            ['slug' => 'division_manager', 'name' => 'Chef de division', 'parent_slug' => 'manager', 'capability_role' => 'division_manager', 'position' => 30, 'is_system' => true],
            ['slug' => 'agent', 'name' => 'Agent', 'parent_slug' => 'division_manager', 'capability_role' => 'agent', 'position' => 40, 'is_system' => true],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_roles');
    }
};
