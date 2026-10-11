<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Before Coolify kept `driver`, `driver_opts` and `labels` of a renamed Compose volume, Docker
     * created the volume without them. Mark every volume that exists at upgrade time, so the parsers
     * keep its old name-only declaration and Docker Compose does not ask to recreate the volume.
     */
    public function up(): void
    {
        // New volumes get their driver options, so the default for new rows is false.
        Schema::table('local_persistent_volumes', function (Blueprint $table) {
            $table->boolean('ignores_compose_driver_options')->default(false);
        });

        // Volumes that exist now were created without driver options, so they keep the old declaration.
        DB::table('local_persistent_volumes')->update(['ignores_compose_driver_options' => true]);
    }

    public function down(): void
    {
        Schema::table('local_persistent_volumes', function (Blueprint $table) {
            $table->dropColumn('ignores_compose_driver_options');
        });
    }
};
