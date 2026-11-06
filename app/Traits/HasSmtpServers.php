<?php

namespace App\Traits;

use Illuminate\Support\Str;

/**
 * Named SMTP servers for email settings.
 *
 * The smtp_* columns always hold the server in use, so sending and the API read them as before.
 * smtp_server_name is the name of that server. smtp_servers holds the other saved servers;
 * switching swaps the server in use with a saved one. The methods change the model but do not save it.
 *
 * @property ?string $smtp_server_name
 * @property ?array<int, array<string, mixed>> $smtp_servers
 */
trait HasSmtpServers
{
    public const DEFAULT_SMTP_SERVER_NAME = 'Default';

    /**
     * The columns that belong to one SMTP server, including its sender, because each server
     * usually sends from its own verified domain.
     */
    public const SMTP_SERVER_COLUMNS = [
        'smtp_from_address',
        'smtp_from_name',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_timeout',
        'smtp_ehlo_domain',
    ];

    public function initializeHasSmtpServers(): void
    {
        $this->mergeCasts(['smtp_servers' => 'encrypted:array']);
        $this->makeHidden('smtp_servers');
    }

    public function smtpServerName(): string
    {
        return filled($this->smtp_server_name) ? $this->smtp_server_name : self::DEFAULT_SMTP_SERVER_NAME;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function savedSmtpServers(): array
    {
        return array_values($this->smtp_servers ?? []);
    }

    /**
     * Keep the server in use in the saved list, so that new values can replace it.
     * A server without a host has nothing worth keeping.
     */
    public function keepActiveSmtpServer(): void
    {
        if (blank($this->smtp_host)) {
            return;
        }

        $server = ['id' => (string) Str::uuid(), 'name' => $this->smtpServerName()];
        foreach (self::SMTP_SERVER_COLUMNS as $column) {
            $server[$column] = $this->{$column};
        }

        $this->smtp_servers = [...$this->savedSmtpServers(), $server];
    }

    /**
     * Use the saved server with the given ID, and keep the server in use in the saved list.
     */
    public function switchToSmtpServer(string $id): bool
    {
        $target = collect($this->savedSmtpServers())->firstWhere('id', $id);
        if ($target === null) {
            return false;
        }

        $this->smtp_servers = collect($this->savedSmtpServers())->reject(fn (array $server) => $server['id'] === $id)->values()->all();
        $this->keepActiveSmtpServer();
        $this->useSmtpServer($target);

        return true;
    }

    /**
     * Delete the server in use and use the first saved server instead.
     */
    public function deleteActiveSmtpServer(): bool
    {
        $servers = $this->savedSmtpServers();
        if ($servers === []) {
            return false;
        }

        $this->smtp_servers = array_slice($servers, 1);
        $this->useSmtpServer($servers[0]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function useSmtpServer(array $server): void
    {
        $this->smtp_server_name = $server['name'] ?? null;
        foreach (self::SMTP_SERVER_COLUMNS as $column) {
            $this->{$column} = $server[$column] ?? null;
        }
    }
}
