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
            $table->boolean('is_logdrain_newrelic_enabled')->default(false);
            $table->string('logdrain_newrelic_license_key')->nullable();
            $table->string('logdrain_newrelic_base_uri')->nullable();

            $table->boolean('is_logdrain_highlight_enabled')->default(false);
            $table->string('logdrain_highlight_project_id')->nullable();

            $table->boolean('is_logdrain_axiom_enabled')->default(false);
            $table->string('logdrain_axiom_dataset_name')->nullable();
            $table->string('logdrain_axiom_api_key')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('is_logdrain_newrelic_enabled');
            $table->dropColumn('logdrain_newrelic_license_key');
            $table->dropColumn('logdrain_newrelic_base_uri');

            $table->dropColumn('is_logdrain_highlight_enabled');
            $table->dropColumn('logdrain_highlight_project_id');

            $table->dropColumn('is_logdrain_axiom_enabled');
            $table->dropColumn('logdrain_axiom_dataset_name');
            $table->dropColumn('logdrain_axiom_api_key');
        });
    }
};
