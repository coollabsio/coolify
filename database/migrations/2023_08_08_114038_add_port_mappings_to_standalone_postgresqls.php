<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->text('ports_mappings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn('ports_mappings');
        });
    }
};
