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
            $table->boolean('force_docker_cleanup')->default(true)->change();
        });
        $serverSettings = ServerSetting::all();
        foreach ($serverSettings as $serverSetting) {
            if ($serverSetting->force_docker_cleanup === false) {
                $serverSetting->force_docker_cleanup = true;
                $serverSetting->docker_cleanup_frequency = '*/10 * * * *';
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
            $table->boolean('force_docker_cleanup')->default(false)->change();
        });
    }
};
