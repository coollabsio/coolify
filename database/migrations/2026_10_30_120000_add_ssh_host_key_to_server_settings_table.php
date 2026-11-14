<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->text('ssh_host_key')->nullable();
            $table->string('ssh_host_key_source')->nullable();
            $table->timestamp('ssh_host_key_trusted_at')->nullable();
            $table->text('ssh_host_key_pending')->nullable();
            $table->boolean('is_ssh_host_key_check_disabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'ssh_host_key',
                'ssh_host_key_source',
                'ssh_host_key_trusted_at',
                'ssh_host_key_pending',
                'is_ssh_host_key_check_disabled',
            ]);
        });
    }
};
