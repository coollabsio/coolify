<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('s3_storages', function (Blueprint $table) {
            $table->boolean('skip_tls_verification')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('s3_storages', function (Blueprint $table) {
            $table->dropColumn('skip_tls_verification');
        });
    }
};
