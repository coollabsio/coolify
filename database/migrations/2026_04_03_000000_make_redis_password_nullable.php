<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('standalone_redis', 'redis_password')) {
            Schema::table('standalone_redis', function (Blueprint $table) {
                $table->text('redis_password')->nullable()->change();
            });
        }
    }
};
