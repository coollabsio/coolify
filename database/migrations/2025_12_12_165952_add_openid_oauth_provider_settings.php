<?php

use App\Models\OauthSetting;
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
        Schema::table('oauth_settings', function (Blueprint $table) {
            $table->string('custom_label')->nullable()->after('enabled');
        });

        OauthSetting::updateOrCreate([
            'provider' => 'openid',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        OauthSetting::where('provider', 'openid')->delete();

        Schema::table('oauth_settings', function (Blueprint $table) {
            $table->dropColumn('custom_label');
        });
    }
};
