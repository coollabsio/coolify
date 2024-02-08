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
        Schema::create('additional_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->onDelete('cascade');
            $table->foreignId('server_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('exited');
            $table->foreignId('standalone_docker_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('additional_destinations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('additional_destinations');
        Schema::table('applications', function (Blueprint $table) {
            $table->string('additional_destinations')->nullable()->after('destination');
        });
    }
};
