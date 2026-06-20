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
        Schema::table('v5_applications', function (Blueprint $table) {
            $table->boolean('ingress_enabled')->default(false);
            $table->unsignedSmallInteger('internal_port')->nullable();
        });

        Schema::create('v5_application_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('v5_applications')->cascadeOnDelete();
            $table->string('domain');
            $table->timestamps();

            $table->unique(['application_id', 'domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v5_application_domains');

        Schema::table('v5_applications', function (Blueprint $table) {
            $table->dropColumn(['ingress_enabled', 'internal_port']);
        });
    }
};
