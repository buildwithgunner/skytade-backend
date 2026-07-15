<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Seed the admin account.
     */
    public function run(): void
    {
        Admin::updateOrCreate(
            ['email' => 'skytradeadmin@gmail.com'],
            [
                'name' => 'Sky Trade Admin',
                'password' => Hash::make('skytrade12345'),
                'staff_id' => 'ADMIN-001',
                'account_status' => 'active',
                'admin_permissions' => Admin::DEFAULT_ADMIN_PERMISSIONS,
                'email_verified_at' => now(),
            ]
        );
    }
}
