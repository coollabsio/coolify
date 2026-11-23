<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('update_channel')->default('stable');
            $table->string('update_custom_tag')->nullable();
            $table->string('previous_update_channel')->nullable();
        });
    }
};
