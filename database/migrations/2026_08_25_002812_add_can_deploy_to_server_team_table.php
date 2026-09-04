<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_team', function (Blueprint $table) {
            $table->boolean('can_deploy')
                ->default(false)
                ->after('can_build');
        });
    }

    public function down(): void
    {
        Schema::table('server_team', function (Blueprint $table) {
            $table->dropColumn('can_deploy');
        });
    }
};
