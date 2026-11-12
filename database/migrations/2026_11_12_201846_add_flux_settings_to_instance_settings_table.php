<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('flux_host')->nullable();
            $table->unsignedInteger('flux_port')->nullable();
            $table->string('flux_signing_key_id')->nullable();
            $table->text('flux_signing_public_key')->nullable();
            $table->text('flux_signing_private_key')->nullable();
            $table->text('flux_internal_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['flux_host', 'flux_port', 'flux_signing_key_id', 'flux_signing_public_key', 'flux_signing_private_key', 'flux_internal_token']);
        });
    }
};
