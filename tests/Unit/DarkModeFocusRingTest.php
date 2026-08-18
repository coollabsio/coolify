<?php

it('uses a thin warning yellow focus ring in dark mode', function () {
    $appStyles = file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
    expect($appStyles)
        ->toContain('ring-2 ring-coollabs dark:ring-1 dark:ring-warning');
});
