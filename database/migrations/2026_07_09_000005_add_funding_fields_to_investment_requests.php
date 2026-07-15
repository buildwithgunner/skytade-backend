<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_requests', function (Blueprint $table) {
            $table->string('funding_source')->default('Bank Account')->after('message');
            $table->string('frequency')->default('one-time')->after('funding_source');
            $table->boolean('attested')->default(true)->after('frequency');
        });
    }

    public function down(): void
    {
        Schema::table('investment_requests', function (Blueprint $table) {
            $table->dropColumn(['funding_source', 'frequency', 'attested']);
        });
    }
};
