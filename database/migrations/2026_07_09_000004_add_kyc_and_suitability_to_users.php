<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // KYC / Personal Details
            $table->string('phone')->nullable()->after('staff_id');
            $table->text('address')->nullable()->after('phone');
            $table->string('zip_code')->nullable()->after('address');
            $table->date('dob')->nullable()->after('zip_code');
            $table->string('government_id')->nullable()->after('dob');

            // Financial Status
            $table->string('annual_income')->nullable()->after('government_id');
            $table->string('employment_status')->nullable()->after('annual_income');
            $table->string('source_of_funds')->nullable()->after('employment_status');

            // Investment Experience
            $table->string('knowledge_level')->nullable()->after('source_of_funds');
            $table->text('experience_assets')->nullable()->after('knowledge_level'); // JSON

            // Risk Tolerance
            $table->string('risk_tolerance_scenario')->nullable()->after('experience_assets');

            // Investment Goals
            $table->string('investment_goals')->nullable()->after('risk_tolerance_scenario');

            // Compliance Flags
            $table->boolean('kyc_completed')->default(false)->after('investment_goals');
            $table->boolean('suitability_completed')->default(false)->after('kyc_completed');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'address', 'zip_code', 'dob', 'government_id',
                'annual_income', 'employment_status', 'source_of_funds',
                'knowledge_level', 'experience_assets',
                'risk_tolerance_scenario', 'investment_goals',
                'kyc_completed', 'suitability_completed',
            ]);
        });
    }
};
