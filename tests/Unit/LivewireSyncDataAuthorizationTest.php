<?php

/**
 * @return list<array{path: string, name: string, visibility: string, body: string, writeBranch: ?string}>
 */
function livewireSyncDataMethods(string $directory): array
{
    $methods = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $contents = file_get_contents($path);
        if ($contents === false || (! preg_match('/function\s+syncData/', $contents) && ! str_contains($contents, 'function syncDatabaseData'))) {
            continue;
        }

        foreach (livewireExtractNamedMethods($contents, $path) as $method) {
            if (! preg_match('/^sync(Data|DatabaseData|ApplicationData)/', $method['name'])) {
                continue;
            }

            $methods[] = $method;
        }
    }

    usort($methods, fn (array $a, array $b): int => [$a['path'], $a['name']] <=> [$b['path'], $b['name']]);

    return $methods;
}

/**
 * @return list<array{path: string, name: string, visibility: string, body: string, writeBranch: ?string}>
 */
function livewireExtractNamedMethods(string $contents, string $path): array
{
    $methods = [];
    $pattern = '/(?P<vis>public|protected|private)\s+function\s+(?P<name>[A-Za-z0-9_]+)\s*\(/';

    if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    foreach ($matches[0] as $index => $fullMatch) {
        $name = $matches['name'][$index][0];
        $visibility = $matches['vis'][$index][0];
        $openBrace = strpos($contents, '{', $fullMatch[1]);
        if ($openBrace === false) {
            continue;
        }

        $body = livewireExtractBraceBody($contents, $openBrace);
        $methods[] = [
            'path' => $path,
            'name' => $name,
            'visibility' => $visibility,
            'body' => $body,
            'writeBranch' => livewireExtractToModelBranch($body),
        ];
    }

    return $methods;
}

function livewireExtractBraceBody(string $contents, int $openBrace): string
{
    $depth = 0;
    $length = strlen($contents);

    for ($i = $openBrace; $i < $length; $i++) {
        $char = $contents[$i];
        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($contents, $openBrace + 1, $i - $openBrace - 1);
            }
        }
    }

    return substr($contents, $openBrace + 1);
}

function livewireExtractToModelBranch(string $body): ?string
{
    if (! preg_match('/if\s*\(\s*\$toModel\s*\)\s*\{/', $body, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $openBrace = strpos($body, '{', $match[0][1]);
    if ($openBrace === false) {
        return null;
    }

    return livewireExtractBraceBody($body, $openBrace);
}

it('keeps every Livewire syncData helper private', function () {
    $violations = [];

    foreach (livewireSyncDataMethods(dirname(__DIR__, 2).'/app/Livewire') as $method) {
        if ($method['visibility'] !== 'private') {
            $relative = str_replace(dirname(__DIR__, 2).'/', '', $method['path']);
            $violations[] = "{$relative}::{$method['name']}() is {$method['visibility']}";
        }
    }

    expect($violations)->toBeEmpty("Livewire syncData helpers must be private:\n".implode("\n", $violations));
});

it('authorizes before every syncData write call', function () {
    $violations = [];

    foreach (livewireSyncDataMethods(dirname(__DIR__, 2).'/app/Livewire') as $syncMethod) {
        $contents = file_get_contents($syncMethod['path']);

        foreach (livewireExtractNamedMethods($contents, $syncMethod['path']) as $caller) {
            $call = '$this->'.$syncMethod['name'].'(true';
            $callPosition = strpos($caller['body'], $call);

            while ($callPosition !== false) {
                $beforeCall = substr($caller['body'], 0, $callPosition);

                if (! str_contains($beforeCall, '$this->authorize(')) {
                    $relative = str_replace(dirname(__DIR__, 2).'/', '', $syncMethod['path']);
                    $violations[] = "{$relative}::{$caller['name']}() calls {$syncMethod['name']}(true) without prior authorization";
                }

                $callPosition = strpos($caller['body'], $call, $callPosition + strlen($call));
            }
        }
    }

    expect($violations)->toBeEmpty("Missing authorization before syncData write calls:\n".implode("\n", array_unique($violations)));
});
