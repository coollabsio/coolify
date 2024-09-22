<?php

use App\Models\ServerSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('docker_cleanup_frequency')->default('0 0 * * *')->change();
        });

        $serverSettings = ServerSetting::all();
        foreach ($serverSettings as $serverSetting) {
            if ($serverSetting->force_docker_cleanup && $serverSetting->docker_cleanup_frequency === '*/10 * * * *') {
                $serverSetting->docker_cleanup_frequency = '0 0 * * *';
                $serverSetting->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('docker_cleanup_frequency')->default('*/10 * * * *')->change();
        });
    }
};
