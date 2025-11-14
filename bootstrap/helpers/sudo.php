<?php

use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

function shouldChangeOwnership(string $path): bool
{
    $path = trim($path);

    $systemPaths = ['/var', '/etc', '/usr', '/opt', '/sys', '/proc', '/dev', '/bin', '/sbin', '/lib', '/lib64', '/boot', '/root', '/home', '/media', '/mnt', '/srv', '/run'];

    foreach ($systemPaths as $systemPath) {
        if ($path === $systemPath || Str::startsWith($path, $systemPath.'/')) {
            return false;
        }
    }

    $isCoolifyPath = Str::startsWith($path, '/data/coolify') || Str::startsWith($path, '/tmp/coolify');

    return $isCoolifyPath;
}
function parseCommandsByLineForSudo(Collection $commands, Server $server): array
{
    $commands = $commands->map(function ($line) {
        if (
            ! str(trim($line))->startsWith([
                'cd',
                'command',
                'echo',
                'true',
                'if',
                'fi',
            ])
        ) {
            return "sudo $line";
        }

        if (str(trim($line))->startsWith('if')) {
            return str_replace('if', 'if sudo', $line);
        }

        return $line;
    });

    $commands = $commands->map(function ($line) use ($server) {
        if (Str::startsWith($line, 'sudo mkdir -p')) {
            $path = trim(Str::after($line, 'sudo mkdir -p'));
            if (shouldChangeOwnership($path)) {
                return "$line && sudo chown -R $server->user:$server->user $path && sudo chmod -R o-rwx $path";
            }

            return $line;
        }

        return $line;
    });

    $commands = $commands->map(function ($line) {
        $line = str($line);

        // Detect complex piped commands that should be wrapped in bash -c
        $isComplexPipeCommand = (
            $line->contains(' | sh') ||
            $line->contains(' | bash') ||
            ($line->contains(' | ') && ($line->contains('||') || $line->contains('&&')))
        );

        // If it's a complex pipe command and starts with sudo, wrap it in bash -c
        if ($isComplexPipeCommand && $line->startsWith('sudo ')) {
            $commandWithoutSudo = $line->after('sudo ')->value();
            // Escape single quotes for bash -c by replacing ' with '\''
            $escapedCommand = str_replace("'", "'\\''", $commandWithoutSudo);

            return "sudo bash -c '$escapedCommand'";
        }

        // For non-complex commands, apply the original logic
        if (str($line)->contains('$(')) {
            $line = $line->replace('$(', '$(sudo ');
        }
        if (! $isComplexPipeCommand && str($line)->contains('||')) {
            $line = $line->replace('||', '|| sudo');
        }
        if (! $isComplexPipeCommand && str($line)->contains('&&')) {
            $line = $line->replace('&&', '&& sudo');
        }
        // Don't insert sudo into pipes for complex commands
        if (! $isComplexPipeCommand && str($line)->contains(' | ')) {
            $line = $line->replace(' | ', ' | sudo ');
        }

        return $line->value();
    });

    return $commands->toArray();
}
function parseLineForSudo(string $command, Server $server): string
{
    if (! str($command)->startSwith('cd') && ! str($command)->startSwith('command')) {
        $command = "sudo $command";
    }
    if (Str::startsWith($command, 'sudo mkdir -p')) {
        $path = trim(Str::after($command, 'sudo mkdir -p'));
        if (shouldChangeOwnership($path)) {
            $command = "$command && sudo chown -R $server->user:$server->user $path && sudo chmod -R o-rwx $path";
        }
    }
    if (str($command)->contains('$(') || str($command)->contains('`')) {
        $command = str($command)->replace('$(', '$(sudo ')->replace('`', '`sudo ')->value();
    }
    if (str($command)->contains('||')) {
        $command = str($command)->replace('||', '|| sudo ')->value();
    }
    if (str($command)->contains('&&')) {
        $command = str($command)->replace('&&', '&& sudo ')->value();
    }

    return $command;
}
