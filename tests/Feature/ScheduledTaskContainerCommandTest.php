<?php

use App\Models\ScheduledTask;

function containerCommandFor(string $command, bool $useShell): string
{
    return (new ScheduledTask(['command' => $command, 'use_shell' => $useShell]))->containerCommand();
}

test('a task with a shell runs the command with sh -c', function () {
    expect(containerCommandFor("echo 'hi' | wc -c", true))->toBe("sh -c 'echo '\\''hi'\\'' | wc -c'");
});

test('a task without a shell splits the command into quoted arguments', function (string $command, string $expected) {
    expect(containerCommandFor($command, false))->toBe($expected);
})->with([
    'plain words' => ['/surreal   export  --user root', "'/surreal' 'export' '--user' 'root'"],
    'single quotes keep everything' => ["echo 'a \"b\" \$X'", "'echo' 'a \"b\" \$X'"],
    'double quotes with escapes' => ['echo "a \\"b\\" \\\\ \\n"', "'echo' 'a \"b\" \\ \\n'"],
    'quotes inside a word' => ['--name="a b"c', "'--name=a bc'"],
    'backslash outside quotes' => ['echo a\\ b', "'echo' 'a b'"],
    'empty argument' => ["cmd '' x", "'cmd' '' 'x'"],
    'line continuation' => ["cmd \\\n  --flag", "'cmd' '--flag'"],
    'no expansion' => ['echo $HOME `id` $(id); rm *', "'echo' '\$HOME' '`id`' '\$(id);' 'rm' '*'"],
]);

test('a task without a shell rejects a command that it cannot split', function (string $command, string $message) {
    expect(fn () => containerCommandFor($command, false))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unclosed quote' => ['echo "hello', 'The command has a quote that is not closed.'],
    'only spaces' => ['   ', 'The command is empty.'],
]);
