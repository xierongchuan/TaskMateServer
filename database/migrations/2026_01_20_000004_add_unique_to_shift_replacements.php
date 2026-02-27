<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Adds unique constraint on shift_id to prevent multiple replacements for the same shift.
     */
    public function up(): void
    {
        Schema::table('shift_replacements', function (Blueprint $table) {
            $table->unique('shift_id', 'shift_replacements_shift_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_replacements', function (Blueprint $table) {
            $table->dropUnique('shift_replacements_shift_id_unique');
        });
    }
};
