<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('instance_settings')->exists()) {
            $settings = DB::table('instance_settings')->get();
            foreach ($settings as $setting) {
                try {
                    DB::table('instance_settings')->where('id', $setting->id)->update([
                        'resend_api_key' => $setting->resend_api_key ? Crypt::encryptString($setting->resend_api_key) : null,
                    ]);
                } catch (Exception $e) {
                    \Log::error('Error encrypting resend_api_key: '.$e->getMessage());
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('instance_settings')->exists()) {
            $settings = DB::table('instance_settings')->get();
            foreach ($settings as $setting) {
                try {
                    DB::table('instance_settings')->where('id', $setting->id)->update([
                        'resend_api_key' => $setting->resend_api_key ? Crypt::decryptString($setting->resend_api_key) : null,
                    ]);
                } catch (Exception $e) {
                    \Log::error('Error decrypting resend_api_key: '.$e->getMessage());
                }
            }
        }
    }
};
