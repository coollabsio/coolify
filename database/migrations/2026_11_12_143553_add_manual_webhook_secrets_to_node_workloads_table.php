<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const COLUMNS = [
        'manual_webhook_secret_github',
        'manual_webhook_secret_gitlab',
        'manual_webhook_secret_bitbucket',
        'manual_webhook_secret_gitea',
    ];

    /**
     * The v4 manual webhook secrets of a cluster application, encrypted like on applications.
     * Existing workloads get random secrets, like a new v4 application.
     */
    public function up(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->text($column)->nullable();
            }
        });

        DB::table('node_workloads')->select('id')->lazyById()->each(function (object $workload): void {
            DB::table('node_workloads')->where('id', $workload->id)->update(
                collect(self::COLUMNS)->mapWithKeys(fn (string $column) => [$column => Crypt::encryptString(Str::random(40))])->all()
            );
        });
    }

    public function down(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
