<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->string('installation_account')->nullable()->after('installation_id');
        });
    }

    public function down(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->dropColumn('installation_account');
        });
    }
};
