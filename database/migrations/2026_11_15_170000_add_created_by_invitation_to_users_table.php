<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks accounts that an invitation created. Existing accounts that may
     * come from an invitation are marked too: a
     * password but no SSO identity, and a pending password reset or, except
     * for root (id 0), no verified email. Password registration on self-hosted
     * instances marks the email verified, an invitation link does not.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('created_by_invitation')->default(false);
        });

        DB::table('users')
            ->whereNotNull('password')
            ->where(fn ($query) => $query->where('force_password_reset', true)
                ->orWhere(fn ($query) => $query->whereNull('email_verified_at')->where('id', '!=', 0)))
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
