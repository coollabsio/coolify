<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deploy of a stack from Git reads its Compose file again and changes the services to match it
 * (decision 0023, step 2). The stack keeps a summary of the last change for its page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_stacks', function (Blueprint $table) {
            $table->json('compose_changes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_stacks', function (Blueprint $table) {
            $table->dropColumn('compose_changes');
        });
    }
};
