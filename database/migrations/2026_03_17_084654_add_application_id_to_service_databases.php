<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete()->after('service_id');
            $table->unsignedBigInteger('service_id')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE service_databases
                ADD CONSTRAINT chk_service_databases_single_parent
                CHECK (
                    (service_id IS NOT NULL AND application_id IS NULL) OR
                    (service_id IS NULL AND application_id IS NOT NULL)
                )
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE service_databases DROP CONSTRAINT chk_service_databases_single_parent');
        }

        DB::table('service_databases')->whereNull('service_id')->delete();

        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropColumn('application_id');
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
        });
    }
};
