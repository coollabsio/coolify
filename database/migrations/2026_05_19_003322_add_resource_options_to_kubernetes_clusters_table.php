<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kubernetes_clusters', function (Blueprint $table) {
            $table->boolean('create_namespace')->default(false)->after('namespace');
            $table->string('service_account_name')->nullable()->after('service_type');
            $table->boolean('create_service_account')->default(false)->after('service_account_name');
            $table->text('image_pull_secrets')->nullable()->after('create_service_account');
            $table->string('storage_class')->nullable()->after('image_pull_secrets');
            $table->string('storage_size')->default('1Gi')->after('storage_class');
            $table->string('ingress_tls_secret')->nullable()->after('ingress_class');
            $table->text('ingress_annotations')->nullable()->after('ingress_tls_secret');
            $table->text('node_selector')->nullable()->after('target_cpu_utilization_percentage');
            $table->text('tolerations')->nullable()->after('node_selector');
            $table->boolean('pod_disruption_budget_enabled')->default(false)->after('tolerations');
            $table->string('pod_disruption_budget_min_available')->nullable()->after('pod_disruption_budget_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('kubernetes_clusters', function (Blueprint $table) {
            $table->dropColumn([
                'create_namespace',
                'service_account_name',
                'create_service_account',
                'image_pull_secrets',
                'storage_class',
                'storage_size',
                'ingress_tls_secret',
                'ingress_annotations',
                'node_selector',
                'tolerations',
                'pod_disruption_budget_enabled',
                'pod_disruption_budget_min_available',
            ]);
        });
    }
};
