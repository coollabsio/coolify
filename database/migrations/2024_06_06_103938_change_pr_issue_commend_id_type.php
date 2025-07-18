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
        Schema::table('application_previews', function (Blueprint $table) {
            $table->string('pull_request_issue_comment_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_previews', function (Blueprint $table) {
            $table->integer('pull_request_issue_comment_id')->nullable()->change();
        });
    }
};
