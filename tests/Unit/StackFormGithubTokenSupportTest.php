<?php

it('supports github token field in laravel rootkit stack form', function () {
    $stackFormFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    expect($stackFormFile)
        ->toContain('SERVICE_GITHUB_TOKEN')
        ->toContain('saveGithubToken')
        ->toContain('withToken($githubToken)')
        ->toContain('buildGithubUrlWithToken');
});

it('renders github token input in stack form view', function () {
    $stackFormView = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/stack-form.blade.php');

    expect($stackFormView)
        ->toContain('fields.SERVICE_GITHUB_TOKEN.value')
        ->toContain('label="GitHub Token"');
});

