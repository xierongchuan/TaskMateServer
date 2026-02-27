<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Creates a partial unique index to prevent multiple open shifts
     * for the same user at the same dealership.
     *
     * Note: PostgreSQL-only feature.
     */
    public function up(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX shifts_user_dealership_open_unique
                ON shifts (user_id, dealership_id)
                WHERE status = 'open' AND shift_end IS NULL
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS shifts_user_dealership_open_unique");
        }
    }
};
