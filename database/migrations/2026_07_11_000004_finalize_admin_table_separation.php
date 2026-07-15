<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->isSqlite()) {
            Schema::disableForeignKeyConstraints();
            $this->rebuildSqliteLoginChallenges();
            $this->rebuildSqliteInvestmentRequests();
            $this->rebuildSqliteMoneyMovements();
            $this->rebuildSqliteTransactionReconciliations();
            $this->rebuildSqliteLedgerEntries();
            Schema::enableForeignKeyConstraints();
        } else {
            Schema::table('login_challenges', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });

            $hasReviewedByFk = !empty(DB::select("
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'investment_requests'
                  AND CONSTRAINT_NAME = 'investment_requests_reviewed_by_foreign'
            "));
            if ($hasReviewedByFk) {
                Schema::table('investment_requests', function (Blueprint $table) {
                    $table->dropForeign(['reviewed_by']);
                });
            }

            $hasPerformedByFk = !empty(DB::select("
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'transaction_reconciliations'
                  AND CONSTRAINT_NAME = 'transaction_reconciliations_performed_by_foreign'
            "));
            if ($hasPerformedByFk) {
                Schema::table('transaction_reconciliations', function (Blueprint $table) {
                    $table->dropForeign(['performed_by']);
                });
            }

            $hasApprovedByFk = !empty(DB::select("
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'money_movements'
                  AND CONSTRAINT_NAME = 'money_movements_approved_by_foreign'
            "));
            if ($hasApprovedByFk) {
                Schema::table('money_movements', function (Blueprint $table) {
                    $table->dropForeign(['approved_by']);
                });
            }
        }

        // The previous migration copied admins by email into the admins table.
        // Re-map any legacy user-based admin references before removing those duplicate rows.
        if (! $this->isSqlite()) {
            DB::statement(<<<'SQL'
                UPDATE investment_requests ir
                INNER JOIN users u ON u.id = ir.reviewed_by
                INNER JOIN admins a ON a.email = u.email
                SET ir.reviewed_by = a.id
                WHERE ir.reviewed_by IS NOT NULL
            SQL);

            if (Schema::hasColumn('money_movements', 'approved_by_admin_id') && Schema::hasColumn('money_movements', 'approved_by')) {
                DB::statement(<<<'SQL'
                    UPDATE money_movements mm
                    INNER JOIN users u ON u.id = mm.approved_by
                    INNER JOIN admins a ON a.email = u.email
                    SET mm.approved_by_admin_id = COALESCE(mm.approved_by_admin_id, a.id)
                    WHERE mm.approved_by IS NOT NULL
                SQL);
            }

            DB::statement(<<<'SQL'
                UPDATE transaction_reconciliations tr
                INNER JOIN users u ON u.id = tr.performed_by
                INNER JOIN admins a ON a.email = u.email
                SET tr.performed_by = a.id
                WHERE tr.performed_by IS NOT NULL
            SQL);
        }

        DB::statement(<<<'SQL'
            UPDATE investment_request_approvals
            SET admin_id = (
                SELECT a.id
                FROM users u
                INNER JOIN admins a ON a.email = u.email
                WHERE u.id = investment_request_approvals.admin_id
                LIMIT 1
            )
            WHERE admin_id IS NOT NULL
              AND EXISTS (
                  SELECT 1
                  FROM users u
                  INNER JOIN admins a ON a.email = u.email
                  WHERE u.id = investment_request_approvals.admin_id
              )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE login_challenges
            SET admin_id = COALESCE(
                    admin_id,
                    (
                        SELECT a.id
                        FROM users u
                        INNER JOIN admins a ON a.email = u.email
                        WHERE u.id = login_challenges.user_id
                        LIMIT 1
                    )
                ),
                user_id = NULL
            WHERE user_id IS NOT NULL
              AND EXISTS (
                  SELECT 1
                  FROM users u
                  INNER JOIN admins a ON a.email = u.email
                  WHERE u.id = login_challenges.user_id
              )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE audit_logs
            SET admin_id = COALESCE(
                    admin_id,
                    (
                        SELECT a.id
                        FROM users u
                        INNER JOIN admins a ON a.email = u.email
                        WHERE u.id = audit_logs.actor_user_id
                        LIMIT 1
                    )
                ),
                actor_user_id = NULL
            WHERE actor_user_id IS NOT NULL
              AND EXISTS (
                  SELECT 1
                  FROM users u
                  INNER JOIN admins a ON a.email = u.email
                  WHERE u.id = audit_logs.actor_user_id
              )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE notifications
            SET recipient_admin_id = COALESCE(
                    recipient_admin_id,
                    (
                        SELECT a.id
                        FROM users u
                        INNER JOIN admins a ON a.email = u.email
                        WHERE u.id = notifications.recipient_user_id
                        LIMIT 1
                    )
                ),
                recipient_user_id = NULL
            WHERE recipient_user_id IS NOT NULL
              AND audience = 'admin'
              AND EXISTS (
                  SELECT 1
                  FROM users u
                  INNER JOIN admins a ON a.email = u.email
                  WHERE u.id = notifications.recipient_user_id
              )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE notification_deliveries
            SET recipient_admin_id = COALESCE(
                    recipient_admin_id,
                    (
                        SELECT a.id
                        FROM users u
                        INNER JOIN admins a ON a.email = u.email
                        WHERE u.id = notification_deliveries.recipient_user_id
                        LIMIT 1
                    )
                ),
                recipient_user_id = NULL
            WHERE recipient_user_id IS NOT NULL
              AND EXISTS (
                  SELECT 1
                  FROM users u
                  INNER JOIN admins a ON a.email = u.email
                  WHERE u.id = notification_deliveries.recipient_user_id
              )
        SQL);

        if (! $this->isSqlite()) {
            $hasReviewedByAdminFk = !empty(DB::select("
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'investment_requests'
                  AND CONSTRAINT_NAME = 'investment_requests_reviewed_by_foreign'
            "));
            if (!$hasReviewedByAdminFk) {
                Schema::table('investment_requests', function (Blueprint $table) {
                    $table->foreign('reviewed_by')->references('id')->on('admins')->nullOnDelete();
                });
            }

            if (Schema::hasColumn('money_movements', 'approved_by')) {
                if (Schema::hasColumn('money_movements', 'approved_by_admin_id')) {
                    Schema::table('money_movements', function (Blueprint $table) {
                        $table->dropColumn('approved_by');
                    });
                }
            }

            if (Schema::hasColumn('money_movements', 'approved_by_admin_id')) {
                $hasApprovedByAdminIdFk = !empty(DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'money_movements'
                      AND CONSTRAINT_NAME = 'money_movements_approved_by_admin_id_foreign'
                "));
                if ($hasApprovedByAdminIdFk) {
                    Schema::table('money_movements', function (Blueprint $table) {
                        $table->dropForeign(['approved_by_admin_id']);
                    });
                }

                DB::statement('ALTER TABLE money_movements CHANGE approved_by_admin_id approved_by BIGINT UNSIGNED NULL');
            }

            $hasApprovedByAdminFk = !empty(DB::select("
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'money_movements'
                  AND CONSTRAINT_NAME = 'money_movements_approved_by_foreign'
            "));
            if (!$hasApprovedByAdminFk) {
                Schema::table('money_movements', function (Blueprint $table) {
                    $table->foreign('approved_by')->references('id')->on('admins')->nullOnDelete();
                });
            }

            $hasPerformedByAdminFk = !empty(DB::select("
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'transaction_reconciliations'
                  AND CONSTRAINT_NAME = 'transaction_reconciliations_performed_by_foreign'
            "));
            if (!$hasPerformedByAdminFk) {
                Schema::table('transaction_reconciliations', function (Blueprint $table) {
                    $table->foreign('performed_by')->references('id')->on('admins')->nullOnDelete();
                });
            }
        }

        DB::table('users')
            ->whereIn('email', DB::table('admins')->select('email'))
            ->delete();

        if (! $this->isSqlite()) {
            $columnsToDrop = [];
            foreach (['staff_id', 'admin_permissions', 'last_mfa_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (!empty($columnsToDrop)) {
                Schema::table('users', function (Blueprint $table) use ($columnsToDrop) {
                    $table->dropColumn($columnsToDrop);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_id')->nullable()->after('account_type');
            $table->json('admin_permissions')->nullable()->after('account_status');
            $table->timestamp('last_mfa_at')->nullable()->after('last_login_ip');
        });

        Schema::table('transaction_reconciliations', function (Blueprint $table) {
            $table->dropForeign(['performed_by']);
            $table->foreign('performed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('money_movements', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
        });

        DB::statement('ALTER TABLE money_movements CHANGE approved_by approved_by_admin_id BIGINT UNSIGNED NULL');

        Schema::table('money_movements', function (Blueprint $table) {
            $table->foreign('approved_by_admin_id')->references('id')->on('admins')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('description')->constrained('users')->nullOnDelete();
        });

        Schema::table('investment_requests', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('login_challenges', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }

    private function isSqlite(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    private function rebuildSqliteLoginChallenges(): void
    {
        Schema::rename('login_challenges', 'login_challenges_old');

        Schema::create('login_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->cascadeOnDelete();
            $table->string('context')->default('admin_login');
            $table->string('challenge_token');
            $table->string('code_hash');
            $table->json('delivery_channels')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            INSERT INTO login_challenges (
                id, user_id, admin_id, context, challenge_token, code_hash,
                delivery_channels, expires_at, consumed_at, attempts, last_attempt_at, created_at, updated_at
            )
            SELECT
                id, user_id, admin_id, context, challenge_token, code_hash,
                delivery_channels, expires_at, consumed_at, attempts, last_attempt_at, created_at, updated_at
            FROM login_challenges_old
        SQL);

        Schema::drop('login_challenges_old');

        Schema::table('login_challenges', function (Blueprint $table) {
            $table->unique('challenge_token');
        });
    }

    private function rebuildSqliteInvestmentRequests(): void
    {
        Schema::rename('investment_requests', 'investment_requests_old');

        Schema::create('investment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->string('asset_type');
            $table->text('message')->nullable();
            $table->string('funding_source')->default('Bank Account');
            $table->string('frequency')->default('one-time');
            $table->boolean('attested')->default(true);
            $table->string('status')->default('pending');
            $table->boolean('requires_dual_approval')->default(false);
            $table->string('approval_state')->default('pending');
            $table->unsignedTinyInteger('approval_count')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->json('risk_flags')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            INSERT INTO investment_requests (
                id, user_id, amount, asset_type, message, funding_source, frequency, attested,
                status, requires_dual_approval, approval_state, approval_count, reviewed_by,
                reviewed_at, review_notes, risk_score, risk_flags, created_at, updated_at
            )
            SELECT
                ir.id,
                ir.user_id,
                ir.amount,
                ir.asset_type,
                ir.message,
                ir.funding_source,
                ir.frequency,
                ir.attested,
                ir.status,
                ir.requires_dual_approval,
                ir.approval_state,
                ir.approval_count,
                CASE
                    WHEN ir.reviewed_by IS NULL THEN NULL
                    ELSE (
                        SELECT a.id
                        FROM users u
                        INNER JOIN admins a ON a.email = u.email
                        WHERE u.id = ir.reviewed_by
                        LIMIT 1
                    )
                END,
                ir.reviewed_at,
                ir.review_notes,
                ir.risk_score,
                ir.risk_flags,
                ir.created_at,
                ir.updated_at
            FROM investment_requests_old ir
        SQL);

        Schema::drop('investment_requests_old');
    }

    private function rebuildSqliteMoneyMovements(): void
    {
        Schema::rename('money_movements', 'money_movements_old');

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
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            INSERT INTO money_movements (
                id, user_id, investment_request_id, type, amount, currency, status,
                reconciliation_status, external_reference, description, approved_by,
                approved_at, posted_at, reconciled_at, created_at, updated_at
            )
            SELECT
                mm.id,
                mm.user_id,
                mm.investment_request_id,
                mm.type,
                mm.amount,
                mm.currency,
                mm.status,
                mm.reconciliation_status,
                mm.external_reference,
                mm.description,
                COALESCE(
                    mm.approved_by_admin_id,
                    (
                        SELECT a.id
                        FROM users u
                        INNER JOIN admins a ON a.email = u.email
                        WHERE u.id = mm.approved_by
                        LIMIT 1
                    )
                ),
                mm.approved_at,
                mm.posted_at,
                mm.reconciled_at,
                mm.created_at,
                mm.updated_at
            FROM money_movements_old mm
        SQL);

        Schema::drop('money_movements_old');
    }

    private function rebuildSqliteTransactionReconciliations(): void
    {
        Schema::rename('transaction_reconciliations', 'transaction_reconciliations_old');

        Schema::create('transaction_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('money_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->decimal('internal_amount', 15, 2);
            $table->decimal('external_amount', 15, 2);
            $table->decimal('difference_amount', 15, 2);
            $table->string('external_reference')->nullable();
            $table->string('status')->default('matched');
            $table->text('notes')->nullable();
            $table->timestamp('reconciled_at');
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            INSERT INTO transaction_reconciliations (
                id, money_movement_id, performed_by, internal_amount, external_amount,
                difference_amount, external_reference, status, notes, reconciled_at, created_at, updated_at
            )
            SELECT
                tr.id,
                tr.money_movement_id,
                CASE
                    WHEN tr.performed_by IS NULL THEN NULL
                    ELSE (
                        SELECT a.id
                        FROM users u
                        INNER JOIN admins a ON a.email = u.email
                        WHERE u.id = tr.performed_by
                        LIMIT 1
                    )
                END,
                tr.internal_amount,
                tr.external_amount,
                tr.difference_amount,
                tr.external_reference,
                tr.status,
                tr.notes,
                tr.reconciled_at,
                tr.created_at,
                tr.updated_at
            FROM transaction_reconciliations_old tr
        SQL);

        Schema::drop('transaction_reconciliations_old');
    }

    private function rebuildSqliteLedgerEntries(): void
    {
        Schema::rename('ledger_entries', 'ledger_entries_old');

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

        DB::statement(<<<'SQL'
            INSERT INTO ledger_entries (
                id, money_movement_id, ledger_account_id, user_id, entry_type, amount, currency,
                reference_type, reference_id, description, meta, effective_at, created_at, updated_at
            )
            SELECT
                id, money_movement_id, ledger_account_id, user_id, entry_type, amount, currency,
                reference_type, reference_id, description, meta, effective_at, created_at, updated_at
            FROM ledger_entries_old
        SQL);

        Schema::drop('ledger_entries_old');
    }
};
