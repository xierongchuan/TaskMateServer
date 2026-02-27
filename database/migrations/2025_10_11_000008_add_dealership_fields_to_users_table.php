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
        // dealership_id column already exists in create_users_table migration
        // This migration was created later but the column was added to the base migration
        // We still want to ensure the foreign key and index exist if they are missing
        if (Schema::hasColumn('users', 'dealership_id')) {
            Schema::table('users', function (Blueprint $table) {
                $foreignKeys = Schema::getForeignKeys('users');
                $hasFK = collect($foreignKeys)->contains(fn($fk) => $fk['columns'] === ['dealership_id']);
                if (!$hasFK) {
                    $table->foreign('dealership_id')->references('id')->on('auto_dealerships')->onDelete('set null');
                }

                $indexes = Schema::getIndexes('users');
                $hasIndex = collect($indexes)->contains(fn($idx) => $idx['columns'] === ['dealership_id']);
                if (!$hasIndex) {
                    $table->index('dealership_id');
                }
            });
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('dealership_id')->unsigned()->nullable()->after('company_id');
            $table->foreign('dealership_id')->references('id')->on('auto_dealerships')->onDelete('set null');
            $table->index('dealership_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $foreignKeys = Schema::getForeignKeys('users');
            foreach ($foreignKeys as $fk) {
                if ($fk['columns'] === ['dealership_id']) {
                    $table->dropForeign($fk['name']);
                }
            }

            $indexes = Schema::getIndexes('users');
            foreach ($indexes as $idx) {
                if ($idx['columns'] === ['dealership_id'] && $idx['name'] !== 'users_dealership_id_primary') {
                    $table->dropIndex($idx['name']);
                }
            }

            // We don't drop the column if it's supposed to be in the base migration
            // To be safe and follow the 'up' logic, we only drop it if it wasn't there before
            // But we can't know that for sure now.
            // However, since 0001_... HAS it, we should probably NOT drop it here.
        });
    }
};
