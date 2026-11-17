<?php

it('does not use @js() in component tag attributes, where Blade does not compile it', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../resources/views'));
    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        preg_match_all('/<(?:x-|livewire:)[\w\-.:]+\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s', $contents, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$tag, $offset]) {
            if (str_contains($tag, '@js(')) {
                $offenders[] = $file->getFilename().':'.(substr_count(substr($contents, 0, $offset), "\n") + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
