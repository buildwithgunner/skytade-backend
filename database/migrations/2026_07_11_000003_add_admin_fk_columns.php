<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('login_challenges', 'admin_id')) {
            Schema::table('login_challenges', function (Blueprint $table) {
                $table->foreignId('admin_id')->nullable()->after('user_id')->constrained('admins')->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('audit_logs', 'admin_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->foreignId('admin_id')->nullable()->after('actor_user_id')->constrained('admins')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('notifications', 'recipient_admin_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->foreignId('recipient_admin_id')->nullable()->after('recipient_user_id')->constrained('admins')->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('notification_deliveries', 'recipient_admin_id')) {
            Schema::table('notification_deliveries', function (Blueprint $table) {
                $table->foreignId('recipient_admin_id')->nullable()->after('recipient_user_id')->constrained('admins')->cascadeOnDelete();
            });
        }

        if ($this->isSqlite()) {
            if (!Schema::hasColumn('investment_request_approvals', 'admin_id')) {
                $this->rebuildSqliteInvestmentRequestApprovals();
            }
        } else {
            if (Schema::hasColumn('investment_request_approvals', 'admin_user_id')) {
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'investment_request_approvals'
                      AND CONSTRAINT_NAME = 'investment_request_approvals_investment_request_id_foreign'
                ");

                $uniqueKeys = DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'investment_request_approvals'
                      AND CONSTRAINT_NAME = 'ira_request_admin_unique'
                      AND CONSTRAINT_TYPE = 'UNIQUE'
                ");

                Schema::table('investment_request_approvals', function (Blueprint $table) use ($foreignKeys, $uniqueKeys) {
                    if (!empty($foreignKeys)) {
                        $table->dropForeign('investment_request_approvals_investment_request_id_foreign');
                    }
                    if (!empty($uniqueKeys)) {
                        $table->dropUnique('ira_request_admin_unique');
                    }
                });

                DB::statement('ALTER TABLE investment_request_approvals CHANGE admin_user_id admin_id BIGINT UNSIGNED NOT NULL');

                Schema::table('investment_request_approvals', function (Blueprint $table) {
                    $table->foreign('investment_request_id', 'investment_request_approvals_investment_request_id_foreign')
                          ->references('id')->on('investment_requests')->cascadeOnDelete();
                    $table->foreign('admin_id')
                          ->references('id')->on('admins')->cascadeOnDelete();
                    $table->unique(['investment_request_id', 'admin_id'], 'ira_request_admin_unique');
                });
            }
        }

        if (!Schema::hasColumn('money_movements', 'approved_by_admin_id')) {
            Schema::table('money_movements', function (Blueprint $table) {
                $table->foreignId('approved_by_admin_id')->nullable()->after('approved_by')->constrained('admins')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('money_movements', function (Blueprint $table) {
            $table->dropForeign(['approved_by_admin_id']);
            $table->dropColumn('approved_by_admin_id');
        });

        Schema::table('investment_request_approvals', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropUnique('ira_request_admin_unique');
            $table->renameColumn('admin_id', 'admin_user_id');
            $table->foreign('admin_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['investment_request_id', 'admin_user_id']);
        });

        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropForeign(['recipient_admin_id']);
            $table->dropColumn('recipient_admin_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['recipient_admin_id']);
            $table->dropColumn('recipient_admin_id');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropColumn('admin_id');
        });

        Schema::table('login_challenges', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropColumn('admin_id');
        });
    }

    private function isSqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    private function rebuildSqliteInvestmentRequestApprovals(): void
    {
        Schema::rename('investment_request_approvals', 'investment_request_approvals_old');

        Schema::create('investment_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('investment_request_id');
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('decision');
            $table->unsignedTinyInteger('sequence')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            INSERT INTO investment_request_approvals (
                id, investment_request_id, admin_id, decision, sequence, notes, created_at, updated_at
            )
            SELECT
                id, investment_request_id, admin_user_id, decision, sequence, notes, created_at, updated_at
            FROM investment_request_approvals_old
        SQL);

        Schema::drop('investment_request_approvals_old');

        Schema::table('investment_request_approvals', function (Blueprint $table) {
            $table->unique(['investment_request_id', 'admin_id'], 'ira_request_admin_unique');
        });
    }
};
