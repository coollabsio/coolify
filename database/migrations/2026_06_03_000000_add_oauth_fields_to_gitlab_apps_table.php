<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gitlab_apps', function (Blueprint $table) {
            $table->longText('client_id')->nullable()->after('app_secret');
            $table->longText('client_secret')->nullable()->after('client_id');
            $table->longText('access_token')->nullable()->after('client_secret');
            $table->longText('refresh_token')->nullable()->after('access_token');
            $table->integer('expires_at')->nullable()->after('refresh_token');
            $table->string('redirect_uri')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('gitlab_apps', function (Blueprint $table) {
            $table->dropColumn([
                'client_id',
                'client_secret',
                'access_token',
                'refresh_token',
                'expires_at',
                'redirect_uri',
            ]);
        });
    }
};
