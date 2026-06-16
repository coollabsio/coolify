<?php

namespace App\Services\Coold;

use Illuminate\Support\Facades\Process;
use Throwable;

class CoolifyCliVersion
{
    /**
     * @return array{available: bool, label: string, version: string|null, message: string, binary: string|null}
     */
    public function check(): array
    {
        $binary = config('coold.coolify_cli_bin', '/usr/local/bin/coolify');

        if (! is_string($binary) || $binary === '') {
            return $this->unavailable(null, 'coolify binary is not configured.');
        }

        try {
            $result = Process::timeout(5)->run(escapeshellarg($binary).' --version');
        } catch (Throwable $exception) {
            return $this->unavailable($binary, $exception->getMessage());
        }

        if (! $result->successful()) {
            return $this->unavailable($binary, trim($result->errorOutput()) ?: 'coolify version check failed.');
        }

        $version = trim($result->output());

        return [
            'available' => true,
            'label' => 'Installed',
            'version' => $version !== '' ? $version : null,
            'message' => $version !== '' ? "Installed version: {$version}." : 'coolify is installed.',
            'binary' => $binary,
        ];
    }

    /**
     * @return array{available: false, label: string, version: null, message: string, binary: string|null}
     */
    private function unavailable(?string $binary, string $message): array
    {
        return [
            'available' => false,
            'label' => 'Unavailable',
            'version' => null,
            'message' => $message,
            'binary' => $binary,
        ];
    }
}
