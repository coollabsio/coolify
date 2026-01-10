<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class InstallPrerequisites
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Server $server)
    {
        $supported_os_type = $server->validateOS();
        if (! $supported_os_type) {
            throw new \Exception('Server OS type is not supported for automated installation. Please install prerequisites manually.');
        }

        $command = collect([]);

        if ($supported_os_type->contains('debian')) {
            $command = $command->merge([
                "echo 'Installing Prerequisites...'",
                'apt-get update -y',
                'command -v curl >/dev/null || apt install -y curl',
                'command -v wget >/dev/null || apt install -y wget',
                'command -v git >/dev/null || apt install -y git',
                'command -v jq >/dev/null || apt install -y jq',
            ]);
        } elseif ($supported_os_type->contains('rhel')) {
            $command = $command->merge([
                "echo 'Installing Prerequisites...'",
                'command -v curl >/dev/null || dnf install -y curl',
                'command -v wget >/dev/null || dnf install -y wget',
                'command -v git >/dev/null || dnf install -y git',
                'command -v jq >/dev/null || dnf install -y jq',
            ]);
        } elseif ($supported_os_type->contains('sles')) {
            $command = $command->merge([
                "echo 'Installing Prerequisites...'",
                'zypper update -y',
                'command -v curl >/dev/null || zypper install -y curl',
                'command -v wget >/dev/null || zypper install -y wget',
                'command -v git >/dev/null || zypper install -y git',
                'command -v jq >/dev/null || zypper install -y jq',
            ]);
        } elseif ($supported_os_type->contains('arch')) {
            // Use -Syu for full system upgrade to avoid partial upgrade issues on Arch Linux
            // --needed flag skips packages that are already installed and up-to-date
            $command = $command->merge([
                "echo 'Installing Prerequisites for Arch Linux...'",
                'pacman -Syu --noconfirm --needed curl wget git jq',
            ]);
        } else {
            throw new \Exception('Unsupported OS type for prerequisites installation');
        }

        $command->push("echo 'Prerequisites installed successfully.'");

        return remote_process($command, $server);
    }
}
