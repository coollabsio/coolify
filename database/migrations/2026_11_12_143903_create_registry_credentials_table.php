<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A team's login for one container registry (decision 0006, "Before release"). Cluster
     * applications pull private images and push built images with it. The password is encrypted.
     */
    public function up(): void
    {
        Schema::create('registry_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('registry');
            $table->string('username');
            $table->text('password');
            $table->timestamps();

            $table->index(['team_id', 'registry']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_credentials');
    }
};
