<?php

namespace App\Services\Coold;

use App\Models\PrivateKey;
use Illuminate\Support\Facades\Process;
use Throwable;

class CoolifyCliBootstrap
{
    /**
     * @param  array{host: string, ssh_user: string, ssh_port: int, wg_listen_port?: int|null, wg_endpoint?: string|null, enable_builder?: bool, builder_capacity?: int|null}  $input
     * @return array{successful: bool, label: string, message: string, output: string|null, errorOutput: string|null, exitCode: int|null}
     */
    public function run(array $input, PrivateKey $privateKey): array
    {
        $binary = $this->stringConfig('coold.coolify_cli_bin', '/usr/local/bin/coolify');
        $sshKeyPath = $this->writeTemporaryPrivateKey($privateKey);

        try {
            $result = Process::timeout(300)->run($this->command($binary, $input, $sshKeyPath));
        } catch (Throwable $exception) {
            return [
                'successful' => false,
                'label' => 'Bootstrap failed',
                'message' => $exception->getMessage(),
                'output' => null,
                'errorOutput' => null,
                'exitCode' => null,
            ];
        } finally {
            @unlink($sshKeyPath);
        }

        $output = trim($result->output());
        $errorOutput = trim($result->errorOutput());

        return [
            'successful' => $result->successful(),
            'label' => $result->successful() ? 'Bootstrap finished' : 'Bootstrap failed',
            'message' => $result->successful()
                ? 'coolify init bootstrap completed successfully.'
                : ($errorOutput !== '' ? $errorOutput : 'coolify init bootstrap failed.'),
            'output' => $output !== '' ? $output : null,
            'errorOutput' => $errorOutput !== '' ? $errorOutput : null,
            'exitCode' => $result->exitCode(),
        ];
    }

    private function command(string $binary, array $input, string $sshKeyPath): string
    {
        $node = sprintf('%s:%d', $input['host'], $input['ssh_port']);
        $parts = [
            $binary,
            'init',
            'bootstrap',
            '--nodes',
            $node,
            '--ssh-key',
            $sshKeyPath,
            '--ssh-user',
            $input['ssh_user'],
        ];

        if (! empty($input['wg_listen_port'])) {
            $this->appendOptional($parts, '--wg-listen-port-overrides', sprintf('%s=%d', $node, $input['wg_listen_port']));
        }

        if (! empty($input['wg_endpoint'])) {
            $this->appendOptional($parts, '--wg-endpoint-overrides', sprintf('%s=%s', $node, $input['wg_endpoint']));
        }

        $this->appendOptional($parts, '--coold-version', $this->stringConfig('coold.coold_version', 'nightly'));
        $this->appendOptional($parts, '--corrosion-version', $this->stringConfig('coold.corrosion_version', 'v1.0.0'));

        if ((bool) ($input['enable_builder'] ?? config('coold.dev_builder_enabled', true))) {
            $parts[] = '--enable-builder';
            $this->appendOptional($parts, '--builder-capacity', (string) ($input['builder_capacity'] ?? config('coold.dev_builder_capacity', 2)));
        }

        $parts[] = '--yes';

        return collect($parts)
            ->map(fn (string $part) => escapeshellarg($part))
            ->implode(' ');
    }

    private function writeTemporaryPrivateKey(PrivateKey $privateKey): string
    {
        $directory = storage_path('app/private/coolify-cli');

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $path = tempnam($directory, 'ssh-key-');
        file_put_contents($path, $privateKey->private_key);
        chmod($path, 0600);

        return $path;
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function appendOptional(array &$parts, string $option, string $value): void
    {
        if ($value === '') {
            return;
        }

        $parts[] = $option;
        $parts[] = $value;
    }

    private function stringConfig(string $key, ?string $default = null): string
    {
        $value = config($key, $default);

        return is_string($value) ? trim($value) : '';
    }
}
