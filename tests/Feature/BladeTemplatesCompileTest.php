<?php

use Illuminate\Support\Facades\Blade;

it('compiles every Blade template to valid PHP', function () {
    $broken = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        try {
            token_get_all(Blade::compileString(file_get_contents($file->getPathname())), TOKEN_PARSE);
        } catch (ParseError $e) {
            $broken[] = str_replace(resource_path('views').'/', '', $file->getPathname()).': '.$e->getMessage();
        }
    }

    expect($broken)->toBe([]);
});
