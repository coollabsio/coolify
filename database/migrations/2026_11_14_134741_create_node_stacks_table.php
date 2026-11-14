<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_stacks', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('node_cluster_id')->constrained('node_clusters')->cascadeOnDelete();
            $table->string('name');
            $table->string('dns_label', 63);
            $table->string('template')->nullable();
            $table->longText('compose_raw');
            $table->json('compose_parsed');
            $table->text('variables')->nullable();
            $table->timestamps();

            $table->unique(['node_cluster_id', 'dns_label']);
        });

        Schema::table('node_workloads', function (Blueprint $table) {
            $table->foreignId('node_stack_id')->nullable()->constrained('node_stacks')->nullOnDelete();
            $table->string('stack_service')->nullable();
            $table->json('stack_health_check')->nullable();
            $table->unique(['node_stack_id', 'stack_service']);
        });

        Schema::table('node_containers', function (Blueprint $table) {
            $table->integer('exit_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_containers', function (Blueprint $table) {
            $table->dropColumn('exit_code');
        });
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropUnique(['node_stack_id', 'stack_service']);
            $table->dropConstrainedForeignId('node_stack_id');
            $table->dropColumn(['stack_service', 'stack_health_check']);
        });
        Schema::dropIfExists('node_stacks');
    }
};
