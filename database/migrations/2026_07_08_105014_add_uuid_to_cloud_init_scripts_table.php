<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cloud_init_scripts', function (Blueprint $table) {
            $table->string('uuid')->nullable()->unique()->after('id');
        });

        DB::table('cloud_init_scripts')
            ->whereNull('uuid')
            ->orderBy('id')
            ->each(function (object $script): void {
                DB::table('cloud_init_scripts')
                    ->where('id', $script->id)
                    ->update(['uuid' => new_public_id()]);
            });
    }

    public function down(): void
    {
        Schema::table('cloud_init_scripts', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
