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
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('priority')->default('normal')->after('status')->index();
            $table->string('extension_status')->nullable()->after('priority');
            $table->dateTime('requested_due_at')->nullable()->after('extension_status');
            $table->text('extension_reason')->nullable()->after('requested_due_at');
            $table->timestamp('extension_requested_at')->nullable()->after('extension_reason');
            $table->timestamp('reminded_d2_at')->nullable()->after('extension_requested_at');
            $table->timestamp('reminded_d0_at')->nullable()->after('reminded_d2_at');
            $table->timestamp('overdue_notified_at')->nullable()->after('reminded_d0_at');
            $table->timestamp('review_reminded_at')->nullable()->after('overdue_notified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropColumn([
                'priority',
                'extension_status',
                'requested_due_at',
                'extension_reason',
                'extension_requested_at',
                'reminded_d2_at',
                'reminded_d0_at',
                'overdue_notified_at',
                'review_reminded_at',
            ]);
        });
    }
};
