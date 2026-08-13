<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_migrations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('target_ip');
            $table->unsignedInteger('target_port')->default(22);
            $table->string('target_user')->default('root');
            $table->foreignId('target_private_key_id')->constrained('private_keys')->cascadeOnDelete();
            $table->string('old_host_ip')->nullable();
            $table->text('package_paths')->nullable();
            $table->json('phases')->nullable();
            $table->json('items')->nullable();
            $table->text('error')->nullable();
            $table->string('dashboard_url')->nullable();
            $table->boolean('dry_run')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_migrations');
    }
};
