<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Before OAuth identities existed, OAuth sign-in matched users by email only
     * and created users without a password. Mark those password-less users so
     * their first OAuth identity links without a provider email verification
     * claim. Users with a password (including root) never get this exemption:
     * otherwise anyone with an unverified provider account with their email
     * could sign in as them.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('created_before_oauth_identities')->default(false);
        });

        DB::table('users')
            ->whereNull('password')
            ->update(['created_before_oauth_identities' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('created_before_oauth_identities');
        });
    }
};
