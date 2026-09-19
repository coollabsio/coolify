<?php

it('does not show placeholder copy when a description is empty', function () {
    $views = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../resources/views')
    );
    $viewContents = '';

    foreach ($views as $view) {
        if ($view->isFile() && $view->getExtension() === 'php') {
            $viewContents .= file_get_contents($view->getPathname());
        }
    }

    expect($viewContents)->not->toContain('No description');
});
