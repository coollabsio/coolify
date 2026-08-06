<?php

it('respects the instance SPA navigation setting after application actions', function () {
    $heading = file_get_contents(__DIR__.'/../../app/Livewire/Project/Application/Heading.php');

    expect(substr_count($heading, "return redirectRoute(\$this, 'project.application.deployment.show'"))
        ->toBe(2)
        ->and($heading)
        ->not->toContain('navigate: false');
});
