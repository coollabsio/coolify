<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('application_settings', 'include_network_hosts_in_build')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->boolean('include_network_hosts_in_build')->default(true)->after('include_source_commit_in_build');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('application_settings', 'include_network_hosts_in_build')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->dropColumn('include_network_hosts_in_build');
            });
        }
    }
};
