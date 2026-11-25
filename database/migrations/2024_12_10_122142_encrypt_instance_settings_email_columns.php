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
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->text('smtp_from_address')->nullable()->change();
            $table->text('smtp_from_name')->nullable()->change();
            $table->text('smtp_recipients')->nullable()->change();
            $table->text('smtp_host')->nullable()->change();
            $table->text('smtp_username')->nullable()->change();
        });

        if (DB::table('instance_settings')->exists()) {
            $settings = DB::table('instance_settings')->get();
            foreach ($settings as $setting) {
                try {
                    DB::table('instance_settings')->where('id', $setting->id)->update([
                        'smtp_from_address' => $setting->smtp_from_address ? Crypt::encryptString($setting->smtp_from_address) : null,
                        'smtp_from_name' => $setting->smtp_from_name ? Crypt::encryptString($setting->smtp_from_name) : null,
                        'smtp_recipients' => $setting->smtp_recipients ? Crypt::encryptString($setting->smtp_recipients) : null,
                        'smtp_host' => $setting->smtp_host ? Crypt::encryptString($setting->smtp_host) : null,
                        'smtp_username' => $setting->smtp_username ? Crypt::encryptString($setting->smtp_username) : null,
                    ]);
                } catch (Exception $e) {
                    \Log::error('Error encrypting instance settings email columns: '.$e->getMessage());
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
            $table->string('smtp_from_address')->nullable()->change();
            $table->string('smtp_from_name')->nullable()->change();
            $table->string('smtp_recipients')->nullable()->change();
            $table->string('smtp_host')->nullable()->change();
            $table->string('smtp_username')->nullable()->change();
        });

        if (DB::table('instance_settings')->exists()) {
            $settings = DB::table('instance_settings')->get();
            foreach ($settings as $setting) {
                try {
                    DB::table('instance_settings')->where('id', $setting->id)->update([
                        'smtp_from_address' => $setting->smtp_from_address ? Crypt::decryptString($setting->smtp_from_address) : null,
                        'smtp_from_name' => $setting->smtp_from_name ? Crypt::decryptString($setting->smtp_from_name) : null,
                        'smtp_recipients' => $setting->smtp_recipients ? Crypt::decryptString($setting->smtp_recipients) : null,
                        'smtp_host' => $setting->smtp_host ? Crypt::decryptString($setting->smtp_host) : null,
                        'smtp_username' => $setting->smtp_username ? Crypt::decryptString($setting->smtp_username) : null,
                    ]);
                } catch (Exception $e) {
                    \Log::error('Error decrypting instance settings email columns: '.$e->getMessage());
                }
            }
        }
    }
};
