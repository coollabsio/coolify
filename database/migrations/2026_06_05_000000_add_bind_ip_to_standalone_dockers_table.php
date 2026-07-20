<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standalone_dockers', function (Blueprint $table) {
            $table->string('bind_ip')->nullable()->after('network');
        });
    }

    public function down(): void
    {
        Schema::table('standalone_dockers', function (Blueprint $table) {
            $table->dropColumn('bind_ip');
        });
    }
};
