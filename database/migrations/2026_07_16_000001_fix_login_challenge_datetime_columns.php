<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE login_challenges
                MODIFY expires_at DATETIME NOT NULL,
                MODIFY consumed_at DATETIME NULL DEFAULT NULL,
                MODIFY last_attempt_at DATETIME NULL DEFAULT NULL
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE login_challenges
                MODIFY expires_at TIMESTAMP NOT NULL,
                MODIFY consumed_at TIMESTAMP NULL DEFAULT NULL,
                MODIFY last_attempt_at TIMESTAMP NULL DEFAULT NULL
        SQL);
    }
};
