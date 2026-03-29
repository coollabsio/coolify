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
        Schema::table('local_persistent_volumes', function (Blueprint $table) {
            $table->string('uuid')->nullable()->after('id');
        });

        DB::table('local_persistent_volumes')
            ->whereNull('uuid')
            ->orderBy('id')
            ->chunk(1000, function ($volumes) {
                foreach ($volumes as $volume) {
                    DB::table('local_persistent_volumes')
                        ->where('id', $volume->id)
                        ->update(['uuid' => (string) new Cuid2]);
                }
            });

        Schema::table('local_persistent_volumes', function (Blueprint $table) {
            $table->string('uuid')->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('local_persistent_volumes', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
