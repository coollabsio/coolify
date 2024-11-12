<?php

use App\Models\ServerSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddServerCleanupFieldsToServerSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $serverSettings = ServerSetting::all();

        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('force_docker_cleanup')->default(false);
            $table->string('docker_cleanup_frequency')->default('*/10 * * * *');
            $table->integer('docker_cleanup_threshold')->default(80);

            // Remove old columns
            $table->dropColumn('cleanup_after_percentage');
            $table->dropColumn('is_force_cleanup_enabled');
        });
        foreach ($serverSettings as $serverSetting) {
            $serverSetting->force_docker_cleanup = $serverSetting->is_force_cleanup_enabled;
            $serverSetting->docker_cleanup_threshold = $serverSetting->cleanup_after_percentage;
            $serverSetting->save();
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $serverSettings = ServerSetting::all();
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('force_docker_cleanup');
            $table->dropColumn('docker_cleanup_frequency');
            $table->dropColumn('docker_cleanup_threshold');

            // Add back old columns
            $table->integer('cleanup_after_percentage')->default(80);
            $table->boolean('force_server_cleanup')->default(false);
            $table->boolean('is_force_cleanup_enabled')->default(false);
        });
        foreach ($serverSettings as $serverSetting) {
            $serverSetting->is_force_cleanup_enabled = $serverSetting->force_docker_cleanup;
            $serverSetting->cleanup_after_percentage = $serverSetting->docker_cleanup_threshold;
            $serverSetting->save();
        }
    }
}
