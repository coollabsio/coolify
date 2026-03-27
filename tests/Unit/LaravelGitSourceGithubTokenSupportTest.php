<?php

it('stores github token in laravel git source component', function () {
    $componentFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/LaravelGitSource.php');

    expect($componentFile)
        ->toContain('public string $githubToken = \'\'')
        ->toContain('SERVICE_GITHUB_TOKEN')
        ->toContain('withToken($this->githubToken)');
});

it('renders github token input in laravel git source view', function () {
    $viewFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/service/laravel-git-source.blade.php');

    expect($viewFile)
        ->toContain('id="githubToken"')
        ->toContain('label="GitHub Token"');
});

