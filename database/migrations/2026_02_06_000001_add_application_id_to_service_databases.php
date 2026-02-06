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
            $table->foreignId('application_id')->nullable()->after('service_id')->constrained()->cascadeOnDelete();
        });

        // Make service_id nullable so Application-linked databases don't need it
        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->change();
        });

        // Ensure exactly one parent is set
        DB::statement('ALTER TABLE service_databases ADD CONSTRAINT chk_single_parent CHECK (service_id IS NOT NULL OR application_id IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE service_databases DROP CONSTRAINT IF EXISTS chk_single_parent');

        // Remove Application-linked records before making service_id non-nullable
        DB::table('service_databases')->whereNull('service_id')->delete();

        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropColumn('application_id');
        });

        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable(false)->change();
        });
    }
};
