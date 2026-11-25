<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A resource can link several named secret manager sources. Existing links become the `default` source.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('secret_manager_links', 'name')) {
            Schema::table('secret_manager_links', function (Blueprint $table) {
                $table->string('name')->default('default')->after('resourceable_id');
            });
        }

        Schema::table('secret_manager_links', function (Blueprint $table) {
            $table->dropUnique(['resourceable_type', 'resourceable_id']);
            $table->unique(['resourceable_type', 'resourceable_id', 'name']);
        });
    }

    public function down(): void
    {
        DB::table('secret_manager_links')->where('name', '!=', 'default')->delete();

        Schema::table('secret_manager_links', function (Blueprint $table) {
            $table->dropUnique(['resourceable_type', 'resourceable_id', 'name']);
            $table->unique(['resourceable_type', 'resourceable_id']);
        });

        Schema::table('secret_manager_links', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
