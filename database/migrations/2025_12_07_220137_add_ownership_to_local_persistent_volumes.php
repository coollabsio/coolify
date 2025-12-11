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
        Schema::table('local_persistent_volumes', function (Blueprint $table) {
            $table->string('chown')->nullable()->after('host_path');
            $table->string('chmod')->nullable()->after('chown');
            $table->boolean('recursive')->default(false)->after('chmod');
            $table->boolean('apply_ownership')->default(true)->after('recursive');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('local_persistent_volumes', function (Blueprint $table) {
            $table->dropColumn(['chown', 'chmod', 'recursive', 'apply_ownership']);
        });
    }
};
