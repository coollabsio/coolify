<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * On instances where migration 2024_10_16_120026 failed or was rolled back
     * (e.g. after a crash), the redis_password column still exists as NOT NULL.
     * Since redis_password was moved to environment_variables, make it nullable
     * so Redis instance creation doesn't fail with a NOT NULL constraint violation.
     */
    public function up(): void
    {
        if (Schema::hasColumn('standalone_redis', 'redis_password')) {
            Schema::table('standalone_redis', function (Blueprint $table) {
                $table->text('redis_password')->nullable()->change();
            });
        }
    }
};
