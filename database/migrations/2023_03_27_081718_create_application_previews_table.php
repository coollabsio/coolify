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
        Schema::create('application_previews', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->integer('pull_request_id');
            $table->string('pull_request_html_url');
            $table->integer('pull_request_issue_comment_id')->nullable();

            $table->string('fqdn')->unique()->nullable();
            $table->string('status')->default('exited');

            $table->foreignId('application_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_previews');
    }
};
