<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks accounts that an invitation created. Of the existing accounts, only
     * those with a pending invitation password are marked: a password, a
     * pending password reset (only an invitation sets it), and no SSO identity.
     * A missing verified email is no sign of an invitation, because password
     * sign-ups on older self-hosted instances never verified the email.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('created_by_invitation')->default(false);
        });

        DB::table('users')
            ->whereNotNull('password')
            ->where('force_password_reset', true)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('oauth_identities')
                ->whereColumn('oauth_identities.user_id', 'users.id'))
            ->update(['created_by_invitation' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('created_by_invitation');
        });
    }
};
