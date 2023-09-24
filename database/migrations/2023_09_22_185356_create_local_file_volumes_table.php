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
        Schema::create('local_file_volumes', function (Blueprint $table) {
            $table->id();
            $table->string('uuid');
            $table->mediumText('fs_path');
            $table->string('mount_path');
            $table->mediumText('content')->nullable();
            $table->nullableMorphs('resource');

            $table->unique(['mount_path', 'resource_id', 'resource_type']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('local_file_volumes');
    }
};
