<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The version of NodeStackComposeMapper that mapped the stored Compose file of a stack. A sync
 * uses it to know which values of the stored file came to the services. Null is a stack from
 * before decision 0028, whose services did not get `deploy.resources`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_stacks', function (Blueprint $table) {
            $table->unsignedSmallInteger('compose_mapping_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_stacks', function (Blueprint $table) {
            $table->dropColumn('compose_mapping_version');
        });
    }
};
