<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        $adminUsers = DB::table('users')->where('role', 'admin')->get();

        foreach ($adminUsers as $row) {
            $exists = DB::table('admins')->where('email', $row->email)->exists();
            if ($exists) {
                continue;
            }

            Admin::create([
                'name' => $row->name,
                'email' => $row->email,
                'password' => $row->password, // already hashed
                'staff_id' => $row->staff_id,
                'account_status' => $row->account_status ?: 'active',
                'admin_permissions' => $row->admin_permissions ? json_decode($row->admin_permissions, true) : null,
                'email_verified_at' => $row->email_verified_at,
                'last_login_at' => $row->last_login_at,
                'last_login_ip' => $row->last_login_ip,
                'last_mfa_at' => $row->last_mfa_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        // Remove admin role from users; downgrade to plain user (if account still needed) or delete
        DB::table('users')->where('role', 'admin')->update([
            'role' => 'user',
            'admin_permissions' => null,
        ]);
    }

    public function down(): void
    {
        // Move admins back into users table
        $admins = DB::table('admins')->get();
        foreach ($admins as $row) {
            DB::table('users')->updateOrInsert(
                ['email' => $row->email],
                [
                    'name' => $row->name,
                    'password' => $row->password,
                    'role' => 'admin',
                    'staff_id' => $row->staff_id,
                    'account_status' => $row->account_status,
                    'admin_permissions' => $row->admin_permissions,
                    'email_verified_at' => $row->email_verified_at,
                    'last_login_at' => $row->last_login_at,
                    'last_login_ip' => $row->last_login_ip,
                    'last_mfa_at' => $row->last_mfa_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]
            );
        }

        DB::table('admins')->delete();
    }
};
