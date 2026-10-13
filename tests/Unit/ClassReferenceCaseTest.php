<?php

/**
 * Returns the real path of a file when it exists with a different letter case.
 */
function differentlyCasedFile(string $root, string $relativePath): ?string
{
    $current = $root;
    foreach (explode('/', $relativePath) as $part) {
        $entries = is_dir($current) ? scandir($current) : [];
        $match = collect($entries)->first(fn (string $entry) => strcasecmp($entry, $part) === 0);
        if ($match === null) {
            return null;
        }
        $current .= '/'.$match;
    }

    return substr($current, strlen($root) + 1);
}

test('every App class reference matches the letter case of its file', function () {
    // Autoloading on Linux is case-sensitive: `App\Helpers\SSLHelper` does not load SslHelper.php.
    $root = dirname(__DIR__, 2);
    $mismatches = [];

    foreach (['app', 'bootstrap', 'routes', 'database', 'config', 'resources/views'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/\bApp\\\\((?:\w+\\\\)*\w+)(?=::|\(|\s|;|,|\))/', file_get_contents($file->getPathname()), $matches);
            foreach (array_unique($matches[1]) as $class) {
                $path = 'app/'.str_replace('\\', '/', $class).'.php';
                if (! file_exists("{$root}/{$path}") && ($realPath = differentlyCasedFile($root, $path))) {
                    $mismatches[] = substr($file->getPathname(), strlen($root) + 1).": App\\{$class} -> {$realPath}";
                }
            }
        }
    }

    expect($mismatches)->toBe([]);
});
