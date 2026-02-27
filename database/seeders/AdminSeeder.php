<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating Admin User...');

        User::updateOrCreate(
            ['login' => 'admin'],
            [
                'full_name' => 'Admin User',
                'password' => Hash::make('password'),
                'role' => Role::OWNER,
            ]
        );

        $this->command->info(' - Created Admin: admin / password');
    }
}
