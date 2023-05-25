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
        Schema::create('gits', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['github', 'gitlab', 'bitbucket', 'custom']);

            $table->string('api_url');
            $table->string('html_url');

            $table->integer('custom_port')->default(22);
            $table->string('custom_user')->default('git');

            $table->longText('webhook_secret')->nullable();
            $table->foreignId('private_key_id')->nullable();
            $table->foreignId('project_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gits');
    }
};
