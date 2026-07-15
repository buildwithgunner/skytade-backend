<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_requests', function (Blueprint $table) {
            $table->boolean('requires_dual_approval')->default(false)->after('status');
            $table->string('approval_state')->default('pending')->after('requires_dual_approval');
            $table->unsignedTinyInteger('approval_count')->default(0)->after('approval_state');
        });
    }

    public function down(): void
    {
        Schema::table('investment_requests', function (Blueprint $table) {
            $table->dropColumn([
                'requires_dual_approval',
                'approval_state',
                'approval_count',
            ]);
        });
    }
};
