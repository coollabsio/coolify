<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_clusters', function (Blueprint $table) {
            $table->text('ingress_tls_key')->nullable();
            $table->unsignedBigInteger('ingress_tls_issuer_node_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_clusters', function (Blueprint $table) {
            $table->dropColumn(['ingress_tls_key', 'ingress_tls_issuer_node_id']);
        });
    }
};
