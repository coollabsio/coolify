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
/**
 * Gives the SSH user the Coolify-created (root-owned) files in a directory and closes the
 * directory to other users. Files that belong to container users (such as a database data
 * folder) and the modes of mounted files stay as they are, so containers can still read them.
 */
function ownershipCommand(string $path, Server $server): string
{
    return "find $path -user root -exec chown $server->user:$server->user {} + && chmod o-rwx $path";
}

/**
 * A line that is one `sudo sh -c '...'` call already runs completely as root. Adding sudo inside the script
 * fails where root is not in sudoers (Alpine), and wrapping it again in `bash -c` needs bash.
 */
function isSingleSudoShellScript(string $line): bool
{
    return preg_match("/^\\s*sudo (?:ba)?sh -c '(?:[^']|'\\\\'')*'\\s*$/", $line) === 1;
}

/**
 * Puts sudo after each ` | ` that the shell sees as a pipe. Pipes inside single or double quotes
 * (a Go template, an `sh -c "..."` script) are text and stay unchanged; a stage that already
 * starts with sudo is not given a second one.
 */
function addSudoAfterUnquotedPipes(string $line): string
{
    $result = '';
    $quote = null;
    $length = strlen($line);

    for ($i = 0; $i < $length; $i++) {
        $char = $line[$i];

        if ($quote === null && substr($line, $i, 3) === ' | ') {
            $result .= str_starts_with(substr($line, $i + 3), 'sudo ') ? ' | ' : ' | sudo ';
            $i += 2;

            continue;
        }

        $result .= $char;

        if ($char === '\\' && $quote !== "'" && $i + 1 < $length) {
            $result .= $line[++$i];
        } elseif ($quote === null && ($char === "'" || $char === '"')) {
            $quote = $char;
        } elseif ($char === $quote) {
            $quote = null;
        }
    }

    return $result;
}

function parseCommandsByLineForSudo(Collection $commands, Server $server): array
{
    $commands = $commands->map(function ($line) {
        $trimmedLine = trim($line);

        // All bash keywords that should not receive sudo prefix
        // Using word boundary matching to avoid prefix collisions (e.g., 'do' vs 'docker', 'if' vs 'ifconfig', 'fi' vs 'find')
        $bashKeywords = [
            'cd',
            'command',
            'declare',
            'echo',
            'export',
            'local',
            'readonly',
            'return',
            'true',
            'if',
            'fi',
            'for',
            'done',
            'while',
            'until',
            'case',
            'esac',
            'select',
            'then',
            'else',
            'elif',
            'break',
            'continue',
            'do',
        ];

        // Special case: comments (no collision risk with '#')
        if (str_starts_with($trimmedLine, '#')) {
            return $line;
        }

        // Negation belongs to the shell, before the elevated command.
        if (preg_match('/^\s*!\s+/', $line)) {
            return preg_replace('/^(\s*(?:!\s+)+)/', '$1sudo ', $line);
        }

        // Check all keywords with word boundary matching
        // Match keyword followed by space, semicolon, or end of line
        foreach ($bashKeywords as $keyword) {
            if (preg_match('/^'.preg_quote($keyword, '/').'(\s|;|$)/', $trimmedLine)) {
                // Keep any shell negation before sudo in the condition.
                if ($keyword === 'if') {
                    return preg_replace('/^(\s*if\s+(?:!\s+)*)/', '$1sudo ', $line);
                }

                return $line;
            }
        }

        return "sudo $line";
    });

    $commands = $commands->map(function ($line) use ($server) {
        if (Str::startsWith($line, 'sudo mkdir -p')) {
            $path = trim(Str::after($line, 'sudo mkdir -p'));
            if (shouldChangeOwnership($path)) {
                // No sudo here: the && rule below adds it. `sudo sudo` fails where root is not in sudoers (Alpine).
                return "$line && ".ownershipCommand($path, $server);
            }

            return $line;
        }

        return $line;
    });

    $commands = $commands->map(function ($line) {
        if (isSingleSudoShellScript($line)) {
            return $line;
        }

        $line = str($line);

        // Detect complex piped commands that should be wrapped in bash -c
        $isComplexPipeCommand = (
            $line->contains(' | sh') ||
            $line->contains(' | bash') ||
            $line->contains(' sh -c ') ||
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
        if (! $isComplexPipeCommand) {
            $line = str(addSudoAfterUnquotedPipes($line->value()));
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
            // No sudo here: the && rule below adds it.
            $command = "$command && ".ownershipCommand($path, $server);
        }
    }
    if (isSingleSudoShellScript($command)) {
        return $command;
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
    // Each pipe stage is a separate process; without sudo, `| tee file` writes as the SSH user.
    $command = addSudoAfterUnquotedPipes($command);

    return $command;
}
