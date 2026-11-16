<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_clusters', function (Blueprint $table) {
            $table->string('image_storage')->nullable();
            $table->string('image_repository')->nullable();
            $table->foreignId('image_registry_credential_id')->nullable()->constrained('registry_credentials')->nullOnDelete();
        });
        // A cluster that runs zot now keeps it.
        DB::table('node_clusters')
            ->whereIn('id', DB::table('node_registries')->whereNotNull('node_id')->select('node_cluster_id'))
            ->update(['image_storage' => 'built_in']);
    }

    public function down(): void
    {
        Schema::table('node_clusters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('image_registry_credential_id');
            $table->dropColumn(['image_storage', 'image_repository']);
        });
    }
};
