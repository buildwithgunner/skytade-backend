<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision');
            $table->unsignedTinyInteger('sequence')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['investment_request_id', 'admin_user_id'], 'ira_request_admin_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_request_approvals');
    }
};
