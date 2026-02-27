<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add shift tracking fields to task_responses
        Schema::table('task_responses', function (Blueprint $table) {
            $table->bigInteger('shift_id')->unsigned()->nullable()->after('user_id');
            $table->boolean('completed_during_shift')->default(false)->after('shift_id');

            $table->foreign('shift_id')
                ->references('id')
                ->on('shifts')
                ->onDelete('set null');

            $table->index('shift_id');
        });

        // Add target_shift_type to tasks for shift-based task scheduling
        Schema::table('tasks', function (Blueprint $table) {
            // 'shift_1', 'shift_2', or null for any shift
            $table->string('target_shift_type', 20)->nullable()->after('task_type');

            $table->index('target_shift_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_responses', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropIndex(['shift_id']);
            $table->dropColumn(['shift_id', 'completed_during_shift']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['target_shift_type']);
            $table->dropColumn('target_shift_type');
        });
    }
};
