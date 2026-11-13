<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cluster registry keeps the last 10 pushed images of each workload, so a rollback finds
 * the image of an earlier revision (decision 0017). Registries with the old default get the new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_registries', function (Blueprint $table) {
            $table->unsignedSmallInteger('keep_tags')->default(10)->change();
        });
        DB::table('node_registries')->where('keep_tags', 5)->update(['keep_tags' => 10]);
    }

    public function down(): void
    {
        Schema::table('node_registries', function (Blueprint $table) {
            $table->unsignedSmallInteger('keep_tags')->default(5)->change();
        });
    }
};
