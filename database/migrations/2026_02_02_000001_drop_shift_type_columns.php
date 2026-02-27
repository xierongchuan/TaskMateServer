<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('shift_type');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['target_shift_type']);
            $table->dropColumn('target_shift_type');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('shift_type', 50)->default('regular')->after('status');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('target_shift_type', 20)->nullable()->after('task_type');
            $table->index('target_shift_type');
        });
    }
};
