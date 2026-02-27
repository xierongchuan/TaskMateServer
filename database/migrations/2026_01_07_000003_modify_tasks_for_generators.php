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
        Schema::table('tasks', function (Blueprint $table) {
            // Link to generator (nullable - for one-time tasks)
            $table->bigInteger('generator_id')->unsigned()->nullable()->after('id');
            $table->foreign('generator_id')->references('id')->on('task_generators')->onDelete('set null');

            // Scheduled date: the date this task is assigned for
            $table->timestampTz('scheduled_date')->nullable()->after('deadline');

            // Archive status
            $table->string('archive_reason', 50)->nullable()->after('archived_at'); // completed, expired

            // Indexes
            $table->index('generator_id');
            $table->index('scheduled_date');
            $table->index('archive_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['generator_id']);
            $table->dropIndex(['generator_id']);
            $table->dropIndex(['scheduled_date']);
            $table->dropIndex(['archive_reason']);
            $table->dropColumn(['generator_id', 'scheduled_date', 'archive_reason']);
        });
    }
};
