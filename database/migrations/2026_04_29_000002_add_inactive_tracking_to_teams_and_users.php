<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('is_inactive')->default(false);
            $table->index('is_inactive');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_inactive')->default(false);
            $table->index('is_inactive');
        });
    }
};
