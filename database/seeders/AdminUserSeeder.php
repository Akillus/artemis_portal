<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('FILAMENT_ADMIN_EMAIL', 'admin@ariadne.local')],
            [
                'name' => env('FILAMENT_ADMIN_NAME', 'ARIADNE Admin'),
                'password' => env('FILAMENT_ADMIN_PASSWORD', 'ChangeMeNow!123'),
                'is_admin' => true,
            ],
        );
    }
}
