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
        if (! Schema::hasColumn('instance_settings', 'instance_domain_certificate_resolver')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->string('instance_domain_certificate_resolver')->nullable()->after('fqdn');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('instance_settings', 'instance_domain_certificate_resolver')) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->dropColumn('instance_domain_certificate_resolver');
            });
        }
    }
};
