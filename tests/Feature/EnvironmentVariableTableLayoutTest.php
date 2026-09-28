<?php

test('managed environment variables are ordered first', function () {
    $component = file_get_contents(app_path('Livewire/Project/Shared/EnvironmentVariable/All.php'));

    expect($component)
        ->toContain("CASE WHEN key LIKE 'SERVICE_FQDN%'")
        ->toMatch("/'kind' => 'managed',[\\s\\S]+?'kind' => 'hardcoded'/");
});

test('missing required environment variables are ordered before generated service variables', function () {
    $component = file_get_contents(app_path('Livewire/Project/Shared/EnvironmentVariable/All.php'));

    $requiredOrder = strpos($component, '$this->missingRequiredEnvironmentVariableIds($isPreview)', strpos($component, 'private function managedEnvironmentVariablesQuery'));
    $generatedOrder = strpos($component, "CASE WHEN key LIKE 'SERVICE_FQDN%'", strpos($component, 'private function managedEnvironmentVariablesQuery'));

    expect($requiredOrder)
        ->not->toBeFalse()
        ->toBeLessThan($generatedOrder)
        ->and($component)
        ->toContain('->filter(fn (EnvironmentVariable $environmentVariable): bool => $environmentVariable->is_really_required)');
});
