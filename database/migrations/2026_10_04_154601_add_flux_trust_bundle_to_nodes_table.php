<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->unsignedInteger('flux_trust_bundle_version')->nullable();
            $table->timestampTz('flux_trust_bundle_acknowledged_at')->nullable();
            $table->string('flux_trust_bundle_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['flux_trust_bundle_version', 'flux_trust_bundle_acknowledged_at', 'flux_trust_bundle_error']);
        });
    }
};
