<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing databases keep their image. Only new databases use Valkey.
     */
    public function up(): void
    {
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->string('image')->default('valkey/valkey:9-alpine')->change();
        });
    }

    public function down(): void
    {
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->string('image')->default('redis:8')->change();
        });
    }
};
