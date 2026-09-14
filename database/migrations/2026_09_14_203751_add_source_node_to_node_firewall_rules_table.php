<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_firewall_rules', function (Blueprint $table) {
            $table->dropUnique('node_firewall_rule_unique');
            $table->foreignId('source_workload_id')->nullable()->change();
            $table->foreignId('source_node_id')->nullable()->after('source_workload_id')->constrained('nodes')->cascadeOnDelete();
            $table->unique(['node_cluster_id', 'source_workload_id', 'destination_workload_id', 'protocol', 'port'], 'node_firewall_workload_rule_unique');
            $table->unique(['node_cluster_id', 'source_node_id', 'destination_workload_id', 'protocol', 'port'], 'node_firewall_node_rule_unique');
        });
    }

    public function down(): void
    {
        DB::table('node_firewall_rules')->whereNull('source_workload_id')->delete();

        Schema::table('node_firewall_rules', function (Blueprint $table) {
            $table->dropUnique('node_firewall_workload_rule_unique');
            $table->dropUnique('node_firewall_node_rule_unique');
            $table->dropConstrainedForeignId('source_node_id');
            $table->foreignId('source_workload_id')->nullable(false)->change();
            $table->unique(['node_cluster_id', 'source_workload_id', 'destination_workload_id', 'protocol', 'port'], 'node_firewall_rule_unique');
        });
    }
};
