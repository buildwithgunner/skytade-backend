<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_requests', function (Blueprint $table) {
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_notes')->nullable()->after('reviewed_at');
            $table->unsignedTinyInteger('risk_score')->default(0)->after('review_notes');
            $table->json('risk_flags')->nullable()->after('risk_score');
        });
    }

    public function down(): void
    {
        Schema::table('investment_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'reviewed_at',
                'review_notes',
                'risk_score',
                'risk_flags',
            ]);
        });
    }
};
