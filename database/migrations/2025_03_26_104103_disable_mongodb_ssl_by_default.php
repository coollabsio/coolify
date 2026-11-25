<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->boolean('enable_ssl')->default(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('standalone_mongodbs', function (Blueprint $table) {
            $table->boolean('enable_ssl')->default(true)->change();
        });
    }
};
