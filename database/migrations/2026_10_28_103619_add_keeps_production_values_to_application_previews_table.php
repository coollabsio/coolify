<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Until v4.5.1, previews used production values: the production variables that had no preview copy,
     * and on public pull requests also the preview copies with the production value. Existing previews
     * keep them, so their databases and services keep working. Previews created later do not get them.
     */
    public function up(): void
    {
        Schema::table('application_previews', function (Blueprint $table) {
            $table->boolean('keeps_production_values')->default(false);
        });

        DB::table('application_previews')->update(['keeps_production_values' => true]);
    }

    public function down(): void
    {
        Schema::table('application_previews', function (Blueprint $table) {
            $table->dropColumn('keeps_production_values');
        });
    }
};
