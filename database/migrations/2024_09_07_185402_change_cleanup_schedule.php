<?php

use App\Models\ServerSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
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
    public function down(): void {}
};
