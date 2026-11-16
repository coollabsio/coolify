<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The image cleanup of a Node (decision 0027), like v4 `docker_cleanup_frequency` and
     * `docker_cleanup_threshold` of a server: a daily run, and a run when the disk use is above
     * the threshold in percent.
     */
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('image_cleanup_frequency')->default('0 0 * * *');
            $table->unsignedTinyInteger('image_cleanup_threshold')->default(80);
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['image_cleanup_frequency', 'image_cleanup_threshold']);
        });
    }
};
