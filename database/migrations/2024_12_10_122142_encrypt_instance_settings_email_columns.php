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
                $table->text('smtp_from_address')->change();
                $table->text('smtp_from_name')->change();
                $table->text('smtp_recipients')->change();
                $table->text('smtp_host')->change();
                $table->text('smtp_username')->change();
            });

            $settings = DB::table('instance_settings')->get();
            foreach ($settings as $setting) {
                DB::table('instance_settings')->where('id', $setting->id)->update([
                    'smtp_from_address' => Crypt::encryptString($setting->smtp_from_address),
                    'smtp_from_name' => Crypt::encryptString($setting->smtp_from_name),
                    'smtp_recipients' => Crypt::encryptString($setting->smtp_recipients),
                    'smtp_host' => Crypt::encryptString($setting->smtp_host),
                    'smtp_username' => Crypt::encryptString($setting->smtp_username),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('smtp_from_address')->change();
            $table->string('smtp_from_name')->change();
            $table->string('smtp_recipients')->change();
            $table->string('smtp_host')->change();
            $table->string('smtp_username')->change();
        });

        if (DB::table('instance_settings')->exists()) {
            $settings = DB::table('instance_settings')->get();
            foreach ($settings as $setting) {
                DB::table('instance_settings')->where('id', $setting->id)->update([
                    'smtp_from_address' => Crypt::decryptString($setting->smtp_from_address),
                    'smtp_from_name' => Crypt::decryptString($setting->smtp_from_name),
                    'smtp_recipients' => Crypt::decryptString($setting->smtp_recipients),
                    'smtp_host' => Crypt::decryptString($setting->smtp_host),
                    'smtp_username' => Crypt::decryptString($setting->smtp_username),
                ]);
            }
        }
    }
};
