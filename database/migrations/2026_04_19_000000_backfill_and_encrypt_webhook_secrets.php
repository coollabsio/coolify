<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BackfillAndEncryptWebhookSecrets extends Migration
{
    public function up(): void
    {
        $columns = [
            'manual_webhook_secret_github',
            'manual_webhook_secret_gitlab',
            'manual_webhook_secret_bitbucket',
            'manual_webhook_secret_gitea',
        ];

        Schema::table('applications', function ($table) use ($columns) {
            foreach ($columns as $col) {
                $table->text($col)->nullable()->change();
            }
        });

        try {
            DB::table('applications')->chunkById(100, function ($apps) use ($columns) {
                foreach ($apps as $app) {
                    $updates = [];
                    foreach ($columns as $col) {
                        $current = $app->{$col};

                        if (empty($current)) {
                            $updates[$col] = Crypt::encryptString(Str::random(40));

                            continue;
                        }

                        try {
                            Crypt::decryptString($current);

                            continue;
                        } catch (Exception) {
                            // Not encrypted yet
                        }

                        $updates[$col] = Crypt::encryptString($current);
                    }
                    if ($updates !== []) {
                        DB::table('applications')->where('id', $app->id)->update($updates);
                    }
                }
            });
        } catch (Exception $e) {
            echo 'Backfilling and encrypting webhook secrets failed.';
            echo $e->getMessage();
        }
    }
}
