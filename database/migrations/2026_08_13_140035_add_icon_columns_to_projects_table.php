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
        Schema::table('projects', function (Blueprint $table) {
            $table->string('icon_path')->nullable();
            $table->string('icon_storage_type')->nullable();
            $table->foreignId('icon_s3_storage_id')->nullable()->constrained('s3_storages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('icon_s3_storage_id');
            $table->dropColumn(['icon_path', 'icon_storage_type']);
        });
    }
};
