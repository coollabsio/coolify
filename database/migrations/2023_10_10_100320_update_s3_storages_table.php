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
        Schema::table('s3_storages', function (Blueprint $table) {
            $table->boolean('is_usable')->default(false);
            $table->boolean('unusable_email_sent')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('s3_storages', function (Blueprint $table) {
            $table->dropColumn('is_usable');
            $table->dropColumn('unusable_email_sent');
        });
    }
};
