<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('division_deletion_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('department', 120);
            $table->unsignedInteger('members_count');
            $table->unsignedInteger('paused_tasks_count');
            $table->foreignId('deleted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deleted_at')->useCurrent();
            $table->timestamps();

            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('division_deletion_logs');
    }
};
