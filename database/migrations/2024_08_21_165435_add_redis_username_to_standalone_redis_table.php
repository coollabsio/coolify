<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->string('redis_username')->default('redis')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->dropColumn('redis_username');
        });
    }
};
