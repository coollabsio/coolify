<?php

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
            $table->boolean('is_logdrain_cloudwatch_enabled')->default(false);
            $table->string('logdrain_cloudwatch_region')->nullable();
            $table->string('logdrain_cloudwatch_group', 512)->nullable();
            $table->string('logdrain_cloudwatch_stream_prefix')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_logdrain_cloudwatch_enabled',
                'logdrain_cloudwatch_region',
                'logdrain_cloudwatch_group',
                'logdrain_cloudwatch_stream_prefix',
            ]);
        });
    }
};
