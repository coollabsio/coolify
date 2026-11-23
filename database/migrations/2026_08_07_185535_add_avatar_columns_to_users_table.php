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
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable();
            $table->string('avatar_storage_type')->nullable();
            $table->foreignId('avatar_s3_storage_id')->nullable()->constrained('s3_storages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('avatar_s3_storage_id');
            $table->dropColumn(['avatar_path', 'avatar_storage_type']);
        });
    }
};
