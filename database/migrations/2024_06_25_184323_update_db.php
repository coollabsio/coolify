<?php

use App\Models\EnvironmentVariable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Visus\Cuid2\Cuid2;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('docker_compose_pr_location');
            $table->dropColumn('docker_compose_pr');
            $table->dropColumn('docker_compose_pr_raw');
        });
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->string('uuid')->nullable()->after('id');
        });

        EnvironmentVariable::all()->each(function (EnvironmentVariable $environmentVariable) {
            $environmentVariable->update([
                'uuid' => (string) new Cuid2(),
            ]);
        });
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->string('uuid')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('docker_compose_pr_location')->nullable()->default('/docker-compose.yaml')->after('docker_compose_location');
            $table->longText('docker_compose_pr')->nullable()->after('docker_compose_location');
            $table->longText('docker_compose_pr_raw')->nullable()->after('docker_compose');
        });
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

    }
};
