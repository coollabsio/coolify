<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddServerCleanupEnabledToServerSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('force_server_cleanup')->default(false);
            $table->string('server_cleanup_cron')->default('*/10 * * * *');
            $table->integer('server_cleanup_threshold')->default(80);

            // Remove old columns
            $table->dropColumn('is_force_cleanup_enabled');
            $table->dropColumn('cleanup_after_percentage');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('force_server_cleanup');
            $table->dropColumn('server_cleanup_cron');
            $table->dropColumn('server_cleanup_threshold');

            // Add back old columns
            $table->boolean('is_force_cleanup_enabled')->default(false);
            $table->integer('cleanup_after_percentage')->default(80);
        });
    }
}