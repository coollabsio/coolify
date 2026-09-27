<?php

it('shows environment variables before the optional secret manager for every resource type', function (string $view, string $resource) {
    $source = file_get_contents(__DIR__."/../../resources/views/livewire/project/{$view}/configuration.blade.php");
    $environmentVariables = '<livewire:project.shared.environment-variable.all :resource="$'.$resource.'" />';
    $secretManager = '<livewire:project.shared.secret-manager-links :resource="$'.$resource.'" />';

    expect($source)
        ->toContain($environmentVariables, $secretManager)
        ->and(strpos($source, $environmentVariables))->toBeLessThan(strpos($source, $secretManager));
})->with([
    'application' => ['application', 'application'],
    'database' => ['database', 'database'],
    'service' => ['service', 'service'],
]);
