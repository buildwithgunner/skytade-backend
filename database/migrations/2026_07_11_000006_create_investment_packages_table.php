<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('minimum_amount', 15, 2)->default(0);
            $table->decimal('maximum_amount', 15, 2)->nullable();
            $table->decimal('roi_percent', 8, 2)->default(0);
            $table->unsignedInteger('duration_days')->default(30);
            $table->decimal('bonus_percent', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_packages');
    }
};
