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
        Schema::create('user_changelog_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('release_tag'); // GitHub tag_name (e.g., "v4.0.0-beta.420.6")
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['user_id', 'release_tag']);
            $table->index('user_id');
            $table->index('release_tag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_changelog_reads');
    }
};
