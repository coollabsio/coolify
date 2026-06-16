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
        Schema::create('v5_servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('private_key_id')->nullable()->constrained('private_keys')->nullOnDelete();
            $table->string('name');
            $table->string('host');
            $table->string('ssh_user');
            $table->unsignedInteger('ssh_port')->default(22);
            $table->string('status')->default('installed');
            $table->json('capabilities')->nullable();
            $table->boolean('builder_enabled')->default(false);
            $table->unsignedInteger('builder_capacity')->default(0);
            $table->timestamp('last_bootstrapped_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'host', 'ssh_port']);
            $table->index(['team_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('v5_servers');
    }
};
