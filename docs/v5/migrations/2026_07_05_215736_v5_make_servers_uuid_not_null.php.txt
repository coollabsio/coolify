<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('v5_servers')
            ->whereNull('uuid')
            ->pluck('id')
            ->each(function (int $id): void {
                DB::table('v5_servers')
                    ->where('id', $id)
                    ->update(['uuid' => Str::lower(Str::random(24))]);
            });

        Schema::table('v5_servers', function (Blueprint $table) {
            $table->string('uuid')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->string('uuid')->nullable()->change();
        });
    }
};
