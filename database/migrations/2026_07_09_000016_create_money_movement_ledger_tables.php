<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->string('currency', 3)->default('USD');
            $table->boolean('allows_negative')->default(false);
            $table->timestamps();
        });

        Schema::create('money_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('investment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('pending');
            $table->string('reconciliation_status')->default('pending');
            $table->string('external_reference')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('money_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entry_type');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('effective_at');
            $table->timestamps();
        });

        Schema::create('transaction_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('money_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('internal_amount', 15, 2);
            $table->decimal('external_amount', 15, 2);
            $table->decimal('difference_amount', 15, 2);
            $table->string('external_reference')->nullable();
            $table->string('status')->default('matched');
            $table->text('notes')->nullable();
            $table->timestamp('reconciled_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_reconciliations');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('money_movements');
        Schema::dropIfExists('ledger_accounts');
    }
};
