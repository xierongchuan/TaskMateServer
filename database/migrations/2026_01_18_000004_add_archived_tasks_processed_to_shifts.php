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
        Schema::table('shifts', function (Blueprint $table) {
            // Track whether overdue tasks were archived for this shift
            $table->boolean('archived_tasks_processed')->default(false)->after('scheduled_end');

            $table->index('archived_tasks_processed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['archived_tasks_processed']);
            $table->dropColumn('archived_tasks_processed');
        });
    }
};
