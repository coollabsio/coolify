<?php

namespace App\Livewire\Concerns;

use App\Models\EmailNotificationSettings;
use App\Models\InstanceSettings;
use App\Support\SmtpPresets;
use Exception;
use Illuminate\Validation\Rule;

/**
 * Named SMTP servers and SMTP templates on an email settings page.
 *
 * The component keeps the SMTP fields of the server in use (smtpHost, smtpPort, ...).
 * Switching and deleting save at once. A new server only empties the fields, and a
 * template fills them in the browser (smtp-server-picker partial); the normal Save stores them.
 *
 * @property InstanceSettings|EmailNotificationSettings $settings
 */
trait ManagesSmtpServers
{
    public ?string $smtpServerName = null;

    public string $selectedSmtpServer = 'active';

    public bool $isNewSmtpServer = false;

    public ?string $newSmtpServerName = null;

    public ?string $renamedSmtpServerName = null;

    public ?string $newSmtpServerTemplate = null;

    /**
     * Save the settings model.
     */
    abstract protected function saveEmailSettings(): void;

    /**
     * Load the component fields from the settings model.
     */
    abstract protected function loadEmailSettings(): void;

    /**
     * @return list<array{value: string, label: string}>
     */
    public function smtpServerOptions(): array
    {
        $options = [['value' => 'active', 'label' => $this->settings->smtpServerName()]];
        foreach ($this->settings->savedSmtpServers() as $server) {
            $options[] = ['value' => $server['id'], 'label' => $server['name']];
        }

        return $options;
    }

    /**
     * Start a new server, with the fields of the selected template or empty fields. The server in use stays saved until the new one is saved.
     */
    public function addSmtpServer(): void
    {
        $this->authorize('update', $this->settings);
        $this->validate([
            'newSmtpServerName' => 'required|string|max:255',
            'newSmtpServerTemplate' => ['nullable', Rule::in(array_keys(SmtpPresets::PRESETS))],
        ], [
            'newSmtpServerName.required' => 'Server name is required.',
        ]);
        $preset = SmtpPresets::PRESETS[$this->newSmtpServerTemplate] ?? null;

        $this->isNewSmtpServer = true;
        $this->smtpServerName = $this->newSmtpServerName;
        $this->newSmtpServerName = null;
        $this->newSmtpServerTemplate = null;
        $this->smtpHost = $preset['host'] ?? null;
        $this->smtpPort = isset($preset['port']) ? (string) $preset['port'] : null;
        $this->smtpEncryption = $preset['encryption'] ?? 'starttls';
        $this->smtpUsername = $preset['username'] ?? null;
        $this->smtpPassword = null;
        $this->smtpTimeout = null;
        $this->smtpEhloDomain = null;
    }

    public function cancelNewSmtpServer(): void
    {
        $this->isNewSmtpServer = false;
        $this->resetErrorBag();
        $this->loadEmailSettings();
    }

    public function switchSmtpServer(string $id)
    {
        try {
            $this->authorize('update', $this->settings);
            if ($id === 'active') {
                return null;
            }
            if (! $this->settings->switchToSmtpServer($id)) {
                throw new Exception('This SMTP server does not exist anymore. Reload the page.');
            }

            $this->saveEmailSettings();
            $this->isNewSmtpServer = false;
            $this->resetErrorBag();
            $this->loadEmailSettings();
            $this->dispatch('success', 'Switched to SMTP server '.$this->settings->smtpServerName().'.');
        } catch (\Throwable $e) {
            $this->selectedSmtpServer = 'active';

            return handleError($e, $this);
        }

        return null;
    }

    /**
     * Rename the server in use. Only the name is saved; other unsaved changes on the page stay unsaved.
     */
    public function renameSmtpServer()
    {
        try {
            $this->authorize('update', $this->settings);
            $this->validate(['renamedSmtpServerName' => 'required|string|max:255'], [
                'renamedSmtpServerName.required' => 'Server name is required.',
            ]);

            $this->smtpServerName = $this->settings->smtp_server_name = $this->renamedSmtpServerName;
            $this->renamedSmtpServerName = null;
            $this->saveEmailSettings();
            $this->dispatch('success', 'SMTP server renamed.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    /**
     * Delete the server in use and use the next saved server.
     */
    public function deleteSmtpServer()
    {
        try {
            $this->authorize('update', $this->settings);
            if ($this->isNewSmtpServer) {
                $this->cancelNewSmtpServer();

                return null;
            }

            $name = $this->settings->smtpServerName();
            if (! $this->settings->deleteActiveSmtpServer()) {
                throw new Exception('This is the only SMTP server. To stop using SMTP, select another provider.');
            }

            $this->saveEmailSettings();
            $this->resetErrorBag();
            $this->loadEmailSettings();
            $this->dispatch('success', 'SMTP server '.$name.' deleted. Now using '.$this->settings->smtpServerName().'.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    /**
     * Copy the SMTP fields, including the sender, to the settings model. A new server first keeps the
     * server in use in the saved list, so set no smtp_* column before this call.
     */
    protected function fillSmtpServerSettings(): void
    {
        if ($this->isNewSmtpServer) {
            $this->settings->keepActiveSmtpServer();
            $this->isNewSmtpServer = false;
        }

        $this->settings->smtp_server_name = $this->smtpServerName;
        $this->settings->smtp_from_address = $this->smtpFromAddress;
        $this->settings->smtp_from_name = $this->smtpFromName;
        $this->settings->smtp_host = $this->smtpHost;
        $this->settings->smtp_port = $this->smtpPort;
        $this->settings->smtp_encryption = $this->smtpEncryption;
        $this->settings->smtp_username = $this->smtpUsername;
        $this->settings->smtp_password = $this->smtpPassword;
        $this->settings->smtp_timeout = $this->smtpTimeout;
        $this->settings->smtp_ehlo_domain = $this->smtpEhloDomain;
    }
}
