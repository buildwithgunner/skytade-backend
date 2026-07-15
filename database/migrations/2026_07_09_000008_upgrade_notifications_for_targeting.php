<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('recipient_user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->string('audience')->default('admin')->after('recipient_user_id');
            $table->string('title')->nullable()->after('audience');
            $table->string('severity')->default('info')->after('title');
            $table->string('action_url')->nullable()->after('data');
        });

        DB::table('notifications')
            ->whereNull('audience')
            ->update([
                'audience' => 'admin',
                'severity' => 'info',
            ]);
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_user_id');
            $table->dropColumn([
                'audience',
                'title',
                'severity',
                'action_url',
            ]);
        });
    }
};
