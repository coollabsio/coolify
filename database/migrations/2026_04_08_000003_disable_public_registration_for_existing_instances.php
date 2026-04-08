<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Disable public self-registration on existing instances. New users must
     * now be created from the Usuarios management module. Fresh installs are
     * untouched so the first-user bootstrap still works.
     */
    public function up(): void
    {
        $hasUsers = DB::table('users')->exists();

        if (! $hasUsers) {
            return;
        }

        DB::table('instance_settings')->update([
            'is_registration_enabled' => false,
        ]);
    }

    public function down(): void
    {
        // No automatic re-enable. Admins can toggle the flag back from
        // Settings → Advanced if they want to reopen registration.
    }
};
