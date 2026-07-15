<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('remember_token', 100)->nullable();
            $table->string('staff_id')->nullable()->unique();
            $table->string('account_status', 32)->default('active');
            $table->json('admin_permissions')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 64)->nullable();
            $table->timestamp('last_mfa_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
