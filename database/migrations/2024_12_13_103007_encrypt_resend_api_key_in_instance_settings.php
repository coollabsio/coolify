<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('instance_settings')->exists()) {
            Schema::table('instance_settings', function (Blueprint $table) {
                $table->text('resend_api_key')->nullable()->change();
            });

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
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->text('resend_api_key')->nullable()->change();
        });

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
