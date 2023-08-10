<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scheduled_database_backups', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->boolean('enabled')->default(true);
            $table->string('save_s3')->default(true);
            $table->string('frequency');
            $table->integer('number_of_backups_locally')->default(7);
            $table->morphs('database');
            $table->foreignId('team_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_database_backups');
    }
};
