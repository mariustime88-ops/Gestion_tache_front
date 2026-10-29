<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('divisions', function (Blueprint $table): void {
            $table->id();
            $table->string('department', 120);
            $table->string('name', 120);
            $table->timestamps();

            $table->unique(['department', 'name']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('division_id')
                ->nullable()
                ->after('division')
                ->constrained('divisions')
                ->restrictOnDelete();
        });

        DB::table('users')
            ->whereNotNull('department')
            ->whereNotNull('division')
            ->select(['department', 'division'])
            ->distinct()
            ->orderBy('department')
            ->orderBy('division')
            ->get()
            ->each(function (object $assignment): void {
                DB::table('divisions')->insertOrIgnore([
                    'department' => $assignment->department,
                    'name' => $assignment->division,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $divisionId = DB::table('divisions')
                    ->where('department', $assignment->department)
                    ->where('name', $assignment->division)
                    ->value('id');

                DB::table('users')
                    ->where('department', $assignment->department)
                    ->where('division', $assignment->division)
                    ->update(['division_id' => $divisionId]);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('division_id');
        });

        Schema::dropIfExists('divisions');
    }
};
