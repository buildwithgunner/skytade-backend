<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_channels')->nullable()->after('phone');
            $table->string('push_channel_key')->nullable()->after('notification_channels');
            $table->timestamp('last_mfa_at')->nullable()->after('last_login_ip');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'notification_channels',
                'push_channel_key',
                'last_mfa_at',
            ]);
        });
    }
};
