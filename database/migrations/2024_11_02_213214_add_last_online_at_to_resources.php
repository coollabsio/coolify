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
        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });
        Schema::table('application_previews', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });
        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });
        Schema::table('standalone_keydbs', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });
        Schema::table('standalone_dragonflies', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->timestamp('last_online_at')->default(now())->after('updated_at');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });
        Schema::table('application_previews', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });
        Schema::table('service_applications', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });
        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });
        Schema::table('standalone_keydbs', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });
        Schema::table('standalone_dragonflies', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });
        Schema::table('standalone_clickhouses', function (Blueprint $table) {
            $table->dropColumn('last_online_at');
        });

    }
};
