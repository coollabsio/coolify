<?php

it('places the github runners save button beside the page title', function () {
    $view = file_get_contents(__DIR__.'/../../resources/views/livewire/server/github-runners.blade.php');

    $titlePosition = strpos($view, '<h2>GitHub Actions Runners</h2>');
    $savePosition = strpos($view, 'form="github-runner-config-form"');
    $formPosition = strpos($view, '<form id="github-runner-config-form"');

    expect($titlePosition)->not->toBeFalse()
        ->and($savePosition)->not->toBeFalse()
        ->and($formPosition)->not->toBeFalse()
        ->and($titlePosition)->toBeLessThan($savePosition)
        ->and($savePosition)->toBeLessThan($formPosition)
        ->and(substr_count($view, '>Save</x-forms.button>'))->toBe(1);
});
