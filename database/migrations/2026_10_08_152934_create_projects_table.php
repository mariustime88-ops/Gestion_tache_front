<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('summary', 280);
            $table->text('description');
            $table->string('organization_name');
            $table->string('department');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->date('deadline')->nullable();
            $table->string('status')->default('open')->index();
            $table->timestamps();

            $table->index(['owner_id', 'created_at']);
            $table->index(['status', 'deadline']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
