<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('division')->nullable()->index()->after('department');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('division')->nullable()->index()->after('department');
        });

        DB::table('tasks')
            ->whereNull('division')
            ->orderBy('id')
            ->chunkById(100, function ($tasks): void {
                foreach ($tasks as $task) {
                    $division = DB::table('users')->where('id', $task->assigned_to)->value('division');

                    if ($division !== null) {
                        DB::table('tasks')->where('id', $task->id)->update(['division' => $division]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('division');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('division');
        });
    }
};
