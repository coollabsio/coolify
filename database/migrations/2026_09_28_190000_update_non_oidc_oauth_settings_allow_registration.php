<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('oauth_settings')
            ->where('provider', '!=', 'oidc')
            ->update(['allow_registration' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversal needed as allow_registration can be managed via UI
    }
};
