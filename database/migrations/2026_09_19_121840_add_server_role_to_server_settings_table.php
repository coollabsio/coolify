<?php

use App\Enums\ServerRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('server_role')->nullable()->default(ServerRole::BOTH->value)->after('is_build_server');
        });

        DB::table('server_settings')
            ->where('is_build_server', true)
            ->update(['server_role' => ServerRole::BUILD->value]);
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('server_role');
        });
    }
};
