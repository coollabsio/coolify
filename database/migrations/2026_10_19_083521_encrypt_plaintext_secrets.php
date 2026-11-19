<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypt secret columns that were stored as plaintext, to match their new `encrypted` casts.
     * Values that are already encrypted are skipped, so the migration is safe to run again.
     */
    public function up(): void
    {
        // Ciphertext does not fit in the original varchar(255) column.
        Schema::table('gitlab_apps', function (Blueprint $table) {
            $table->text('app_secret')->nullable()->change();
        });

        $this->encryptColumns('github_apps', ['client_secret', 'webhook_secret']);
        $this->encryptColumns('gitlab_apps', ['app_secret']);
        $this->encryptColumns('standalone_mariadbs', ['mariadb_root_password']);
        // The old accessor stored encrypt($value), which serializes the value before encryption.
        $this->encryptColumns('standalone_mongodbs', ['mongo_initdb_root_password'], legacySerialized: true);
        $this->encryptColumns('server_settings', ['logdrain_custom_config']);
        $this->encryptColumns('team_invitations', ['link']);
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function encryptColumns(string $table, array $columns, bool $legacySerialized = false): void
    {
        try {
            DB::table($table)->select(['id', ...$columns])->chunkById(100, function ($rows) use ($table, $columns, $legacySerialized) {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($columns as $column) {
                        $value = $row->{$column};

                        if (blank($value)) {
                            continue;
                        }

                        $plaintext = $this->plaintextToEncrypt($value, $legacySerialized);

                        if ($plaintext !== null) {
                            $updates[$column] = Crypt::encryptString($plaintext);
                        }
                    }

                    if ($updates !== []) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            });
        } catch (Exception $e) {
            echo "Encrypting secrets in {$table} failed.";
            echo $e->getMessage();
        }
    }

    /**
     * Return the plaintext that still needs encryption, or null when the value is already stored as encryptString() output.
     */
    private function plaintextToEncrypt(string $value, bool $legacySerialized): ?string
    {
        try {
            $decrypted = Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }

        if ($legacySerialized) {
            $unserialized = @unserialize($decrypted, ['allowed_classes' => false]);

            if (is_string($unserialized) && serialize($unserialized) === $decrypted) {
                return $unserialized;
            }
        }

        return null;
    }
};
