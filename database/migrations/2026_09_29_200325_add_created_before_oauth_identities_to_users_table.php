<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Before OAuth identities existed, OAuth sign-in matched users by email only.
     * Mark every user that exists at upgrade time so their first OAuth identity
     * links without a provider email verification claim.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('created_before_oauth_identities')->default(false);
        });

        DB::table('users')->update(['created_before_oauth_identities' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('created_before_oauth_identities');
        });
    }
};
