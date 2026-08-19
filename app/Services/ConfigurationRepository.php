<?php

namespace App\Services;

use App\Support\SmtpTransportFactory;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Mail;

class ConfigurationRepository
{
    private Repository $config;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    public function updateMailConfig($settings): void
    {
        $from = mail_from_identity($settings);

        if ($settings->resend_enabled) {
            $this->config->set('mail.default', 'resend');
            $this->applyMailFrom($from);
            $this->config->set('resend.api_key', $settings->resend_api_key);

            return;
        }

        if ($settings->smtp_enabled) {
            $mailerOptions = SmtpTransportFactory::mailerOptions($settings);
            $localDomain = $settings->smtp_ehlo_domain
                ?? $this->config->get('mail.mailers.smtp.local_domain');

            $this->config->set('mail.default', 'smtp');
            $this->applyMailFrom($from);
            $this->config->set('mail.mailers.smtp', [
                'transport' => 'smtp',
                'scheme' => $mailerOptions['scheme'],
                'host' => $settings->smtp_host,
                'port' => $settings->smtp_port,
                'encryption' => $mailerOptions['encryption'],
                'username' => $settings->smtp_username,
                'password' => $settings->smtp_password,
                'timeout' => $settings->smtp_timeout,
                'local_domain' => $localDomain,
                'auto_tls' => $mailerOptions['auto_tls'],
            ]);
        }
    }

    /**
     * @param  array{address: string, name: string}  $from
     */
    private function applyMailFrom(array $from): void
    {
        $this->config->set('mail.from.address', $from['address']);
        $this->config->set('mail.from.name', $from['name']);

        if (app()->bound('mail.manager')) {
            Mail::purge();
        }
    }

    public function disableSshMux(): void
    {
        $this->config->set('constants.ssh.mux_enabled', false);
    }
}
