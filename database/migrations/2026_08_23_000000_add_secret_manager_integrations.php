<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_tokens', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('capabilities');
        });

        Schema::create('secret_manager_links', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('resourceable_type');
            $table->unsignedBigInteger('resourceable_id');
            $table->foreignId('integration_token_id')->constrained()->cascadeOnDelete();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['resourceable_type', 'resourceable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_manager_links');

        Schema::table('integration_tokens', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
