<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptExistingClickhouseAdminPasswords extends Migration
{
    public function up(): void
    {
        try {
            DB::table('standalone_clickhouses')->chunkById(100, function ($clickhouses) {
                foreach ($clickhouses as $clickhouse) {
                    $password = $clickhouse->clickhouse_admin_password;

                    if (empty($password)) {
                        continue;
                    }

                    // Skip if already encrypted (idempotent)
                    try {
                        Crypt::decryptString($password);

                        continue;
                    } catch (Exception) {
                        // Not encrypted yet — encrypt it
                    }

                    DB::table('standalone_clickhouses')
                        ->where('id', $clickhouse->id)
                        ->update(['clickhouse_admin_password' => Crypt::encryptString($password)]);
                }
            });
        } catch (Exception $e) {
            echo 'Encrypting ClickHouse admin passwords failed.';
            echo $e->getMessage();
        }
    }
}
