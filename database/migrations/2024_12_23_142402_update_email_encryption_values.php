<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class UpdateEmailEncryptionValues extends Migration
{
    /**
     * The encryption mappings.
     */
    private array $encryptionMappings = [
        'tls' => 'starttls',
        'ssl' => 'tls',
        '' => 'none',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            DB::beginTransaction();

            $instanceSettings = DB::table('instance_settings')->get();
            foreach ($instanceSettings as $setting) {
                try {
                    if (array_key_exists($setting->smtp_encryption, $this->encryptionMappings)) {
                        DB::table('instance_settings')
                            ->where('id', $setting->id)
                            ->update([
                                'smtp_encryption' => $this->encryptionMappings[$setting->smtp_encryption],
                            ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Failed to update instance_settings encryption for ID: '.$setting->id, [
                        'error' => $e->getMessage(),
                        'old_value' => $setting->smtp_encryption,
                    ]);
                }
            }

            $emailSettings = DB::table('email_notification_settings')->get();
            foreach ($emailSettings as $setting) {
                try {
                    if (array_key_exists($setting->smtp_encryption, $this->encryptionMappings)) {
                        DB::table('email_notification_settings')
                            ->where('id', $setting->id)
                            ->update([
                                'smtp_encryption' => $this->encryptionMappings[$setting->smtp_encryption],
                            ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Failed to update email_notification_settings encryption for ID: '.$setting->id, [
                        'error' => $e->getMessage(),
                        'old_value' => $setting->smtp_encryption,
                    ]);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to complete email encryption migration', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            DB::beginTransaction();

            $reverseMapping = [
                'starttls' => 'tls',
                'tls' => 'ssl',
                'none' => '',
            ];

            $instanceSettings = DB::table('instance_settings')->get();
            foreach ($instanceSettings as $setting) {
                try {
                    if (array_key_exists($setting->smtp_encryption, $reverseMapping)) {
                        DB::table('instance_settings')
                            ->where('id', $setting->id)
                            ->update([
                                'smtp_encryption' => $reverseMapping[$setting->smtp_encryption],
                            ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Failed to reverse instance_settings encryption for ID: '.$setting->id, [
                        'error' => $e->getMessage(),
                        'old_value' => $setting->smtp_encryption,
                    ]);
                }
            }

            $emailSettings = DB::table('email_notification_settings')->get();
            foreach ($emailSettings as $setting) {
                try {
                    if (array_key_exists($setting->smtp_encryption, $reverseMapping)) {
                        DB::table('email_notification_settings')
                            ->where('id', $setting->id)
                            ->update([
                                'smtp_encryption' => $reverseMapping[$setting->smtp_encryption],
                            ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('Failed to reverse email_notification_settings encryption for ID: '.$setting->id, [
                        'error' => $e->getMessage(),
                        'old_value' => $setting->smtp_encryption,
                    ]);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to complete email encryption rollback migration', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
