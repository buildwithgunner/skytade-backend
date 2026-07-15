<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status')->default('active')->after('staff_id');
            $table->json('admin_permissions')->nullable()->after('account_status');
            $table->timestamp('last_login_at')->nullable()->after('admin_permissions');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'account_status',
                'admin_permissions',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
