<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The public host key of an SFTP server ("ssh-ed25519 AAAA..."), saved on the first successful connection. Every
     * later connection of Coolify and rclone must see the same key. Null: the next connection test saves it.
     */
    public function up(): void
    {
        Schema::table('s3_storages', function (Blueprint $table) {
            $table->text('sftp_host_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('s3_storages', function (Blueprint $table) {
            $table->dropColumn('sftp_host_key');
        });
    }
};
