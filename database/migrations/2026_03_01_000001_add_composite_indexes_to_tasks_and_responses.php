<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['is_active', 'archived_at', 'deadline'], 'tasks_active_archived_deadline_idx');
        });
        Schema::table('task_responses', function (Blueprint $table) {
            $table->index(['task_id', 'status'], 'task_responses_task_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_active_archived_deadline_idx');
        });
        Schema::table('task_responses', function (Blueprint $table) {
            $table->dropIndex('task_responses_task_status_idx');
        });
    }
};
