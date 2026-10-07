<?php

it('authorizes secret-enabled environment variable inputs at the component boundary', function () {
    $addView = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/add.blade.php'));
    $showView = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show.blade.php'));

    // Show resolves the resource through its computed `resource` property.
    foreach ([[$addView, '$resource'], [$showView, '$this->resource']] as [$view, $resourceExpression]) {
        preg_match('/<x-forms\.env-var-input[\s\S]*?\/>/', $view, $matches);

        expect($matches[0] ?? '')
            ->toContain('canGate="manageEnvironment"')
            ->toContain(':canResource="'.$resourceExpression.'"');
    }
});
