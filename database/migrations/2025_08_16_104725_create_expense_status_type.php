<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip ENUM creation for SQLite (testing)
        if (config('database.default') === 'sqlite') {
            return;
        }

        DB::statement("
            DO \$\$ BEGIN
                CREATE TYPE expense_status AS ENUM (
                  'pending',
                  'approved',
                  'declined',
                  'issued',
                  'cancelled'
                );
            EXCEPTION
                WHEN duplicate_object THEN null;
            END \$\$;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') === 'sqlite') {
            return;
        }

        DB::statement("DROP TYPE IF EXISTS expense_status;");
    }
};
