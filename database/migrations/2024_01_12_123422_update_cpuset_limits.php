<?php

use App\Models\Application;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
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
            $table->string('limits_cpuset')->nullable()->default(null)->change();
        });
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->string('limits_cpuset')->nullable()->default(null)->change();
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->string('limits_cpuset')->nullable()->default(null)->change();
        });
        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->string('limits_cpuset')->nullable()->default(null)->change();
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->string('limits_cpuset')->nullable()->default(null)->change();
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->string('limits_cpuset')->nullable()->default(null)->change();
        });
        Application::where('limits_cpuset', '0')->update(['limits_cpuset' => null]);
        StandalonePostgresql::where('limits_cpuset', '0')->update(['limits_cpuset' => null]);
        StandaloneRedis::where('limits_cpuset', '0')->update(['limits_cpuset' => null]);
        StandaloneMariadb::where('limits_cpuset', '0')->update(['limits_cpuset' => null]);
        StandaloneMysql::where('limits_cpuset', '0')->update(['limits_cpuset' => null]);
        StandaloneMongodb::where('limits_cpuset', '0')->update(['limits_cpuset' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('limits_cpuset')->nullable()->default('0')->change();
        });
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->string('limits_cpuset')->nullable()->default('0')->change();
        });
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->string('limits_cpuset')->nullable()->default('0')->change();
        });
        Schema::table('standalone_mariadbs', function (Blueprint $table) {
            $table->string('limits_cpuset')->nullable()->default('0')->change();
        });
        Schema::table('standalone_mysqls', function (Blueprint $table) {
            $table->string('limits_cpuset')->nullable()->default('0')->change();
        });
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->string('limits_cpuset')->nullable()->default('0')->change();
        });
        Application::where('limits_cpuset', null)->update(['limits_cpuset' => '0']);
        StandalonePostgresql::where('limits_cpuset', null)->update(['limits_cpuset' => '0']);
        StandaloneRedis::where('limits_cpuset', null)->update(['limits_cpuset' => '0']);
        StandaloneMariadb::where('limits_cpuset', null)->update(['limits_cpuset' => '0']);
        StandaloneMysql::where('limits_cpuset', null)->update(['limits_cpuset' => '0']);
        StandaloneMongodb::where('limits_cpuset', null)->update(['limits_cpuset' => '0']);
    }
};
