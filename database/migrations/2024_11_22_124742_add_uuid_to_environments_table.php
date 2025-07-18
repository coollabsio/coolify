<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Visus\Cuid2\Cuid2;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->string('uuid')->after('id')->nullable()->unique();
        });

        DB::table('environments')
            ->whereNull('uuid')
            ->chunkById(100, function ($environments) {
                foreach ($environments as $environment) {
                    DB::table('environments')
                        ->where('id', $environment->id)
                        ->update(['uuid' => (string) new Cuid2]);
                }
            });

        Schema::table('environments', function (Blueprint $table) {
            $table->string('uuid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
