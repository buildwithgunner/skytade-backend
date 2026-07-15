<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

return new class extends Migration
{
    private array $columns = [
        'phone',
        'address',
        'zip_code',
        'dob',
        'government_id',
        'annual_income',
        'employment_status',
        'source_of_funds',
    ];

    public function up(): void
    {
        User::query()
            ->where(function ($query) {
                foreach ($this->columns as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->chunkById(50, function ($users): void {
                foreach ($users as $user) {
                    $updates = [];

                    foreach ($this->columns as $column) {
                        $value = $user->getRawOriginal($column);

                        if ($value === null || $value === '') {
                            continue;
                        }

                        if ($this->isEncryptedValue($value)) {
                            continue;
                        }

                        $updates[$column] = Crypt::encryptString((string) $value);
                    }

                    if ($updates !== []) {
                        DB::table('users')->where('id', $user->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        User::query()
            ->where(function ($query) {
                foreach ($this->columns as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->chunkById(50, function ($users): void {
                foreach ($users as $user) {
                    $updates = [];

                    foreach ($this->columns as $column) {
                        $value = $user->getRawOriginal($column);

                        if (! is_string($value) || $value === '') {
                            continue;
                        }

                        try {
                            $updates[$column] = Crypt::decryptString($value);
                        } catch (DecryptException) {
                            continue;
                        }
                    }

                    if ($updates !== []) {
                        DB::table('users')->where('id', $user->id)->update($updates);
                    }
                }
            });
    }

    private function isEncryptedValue(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
