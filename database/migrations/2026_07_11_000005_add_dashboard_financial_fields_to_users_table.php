<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('account_balance', 15, 2)->default(0)->after('suitability_completed');
            $table->decimal('total_profit', 15, 2)->default(0)->after('account_balance');
            $table->decimal('bonus_balance', 15, 2)->default(0)->after('total_profit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'account_balance',
                'total_profit',
                'bonus_balance',
            ]);
        });
    }
};
