<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('team_invitations', 'access')) {
            Schema::table('team_invitations', function (Blueprint $table) {
                $table->json('access')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropColumn('access');
        });
    }
};
