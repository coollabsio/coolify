<?php

namespace App\Services;

use Illuminate\Config\Repository;

class ConfigurationRepository
{
    private Repository $config;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    public function updateMailConfig($settings): void
    {
        if ($settings->resend_enabled) {
            $this->config->set('mail.default', 'resend');
            $this->config->set('mail.from.address', $settings->smtp_from_address ?? 'test@example.com');
            $this->config->set('mail.from.name', $settings->smtp_from_name ?? 'Test');
            $this->config->set('resend.api_key', $settings->resend_api_key);

            return;
        }

        if ($settings->smtp_enabled) {
            $encryption = match (strtolower($settings->smtp_encryption)) {
                'starttls' => null,
                'tls' => 'tls',
                'none' => null,
                default => null,
            };

            $this->config->set('mail.default', 'smtp');
            $this->config->set('mail.from.address', $settings->smtp_from_address ?? 'test@example.com');
            $this->config->set('mail.from.name', $settings->smtp_from_name ?? 'Test');
            $this->config->set('mail.mailers.smtp', [
                'transport' => 'smtp',
                'host' => $settings->smtp_host,
                'port' => $settings->smtp_port,
                'encryption' => $encryption,
                'username' => $settings->smtp_username,
                'password' => $settings->smtp_password,
                'timeout' => $settings->smtp_timeout,
                'local_domain' => null,
                'auto_tls' => $settings->smtp_encryption === 'none' ? '0' : '',
            ]);
        }
    }

    public function disableSshMux(): void
    {
        $this->config->set('constants.ssh.mux_enabled', false);
    }
}
