<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Last active team, restored on login. Nullable: no persisted choice yet.
            // Not a foreign key because team ids include the 0 sentinel and teams can
            // be deleted out from under a user; validity is checked against the user's
            // team membership at read time.
            $table->unsignedBigInteger('current_team_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('current_team_id');
        });
    }
};
