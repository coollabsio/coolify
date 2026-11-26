<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The protocol of the storage: s3, sftp, ftps, or webdav. Storages that are not S3 have no bucket. An SFTP storage
     * logs in with a private key of its team or with a password.
     */
    public function up(): void
    {
        Schema::table('s3_storages', function (Blueprint $table) {
            $table->string('type')->default('s3');
            $table->longText('bucket')->nullable()->change();
            $table->foreignId('private_key_id')->nullable()->constrained('private_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('s3_storages')->whereNull('bucket')->update(['bucket' => '']);

        Schema::table('s3_storages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('private_key_id');
            $table->dropColumn('type');
            $table->longText('bucket')->nullable(false)->change();
        });
    }
};
