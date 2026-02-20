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
        Schema::table('users', function (Blueprint $table) {
            // Per-user flag set when the instance has oauth_force_only enabled.
            // When true, this user may only authenticate via OAuth — password
            // authentication is blocked in the Fortify login pipeline.
            $table->boolean('oauth_force_only')->default(false)->after('two_factor_confirmed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('oauth_force_only');
        });
    }
};
