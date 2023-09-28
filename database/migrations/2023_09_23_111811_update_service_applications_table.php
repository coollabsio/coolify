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
        Schema::table('service_applications', function (Blueprint $table) {
            $table->boolean('exclude_from_status')->default(false);
            $table->boolean('required_fqdn')->default(false);
            $table->string('image')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_applications', function (Blueprint $table) {
            $table->dropColumn('exclude_from_status');
            $table->dropColumn('required_fqdn');
            $table->dropColumn('image');
        });
    }
};
