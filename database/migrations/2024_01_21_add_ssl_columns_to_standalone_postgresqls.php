<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->boolean('enable_ssl')->default(false);
            $table->string('ssl_mode')->nullable();
            $table->string('custom_domain')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn(['enable_ssl', 'ssl_mode', 'custom_domain']);
        });
    }
}; 