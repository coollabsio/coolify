<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kubernetes_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('uuid')->unique();
            $table->string('namespace')->default('default');
            $table->string('context')->nullable();
            $table->string('kubeconfig_path')->nullable();
            $table->longText('kubeconfig')->nullable();
            $table->string('ingress_class')->default('traefik');
            $table->string('service_type')->default('ClusterIP');
            $table->unsignedInteger('replicas')->default(1);
            $table->boolean('autoscaling_enabled')->default(false);
            $table->unsignedInteger('min_replicas')->default(1);
            $table->unsignedInteger('max_replicas')->default(3);
            $table->unsignedInteger('target_cpu_utilization_percentage')->default(70);

            $table->foreignId('server_id');
            $table->unique(['server_id', 'name']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kubernetes_clusters');
    }
};
