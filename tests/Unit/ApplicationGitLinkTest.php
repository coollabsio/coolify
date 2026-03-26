<?php

use App\Models\Application;
use App\Models\GithubApp;

it('generates correct commit link for GitHub source with SSH', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_repository = 'git@github.com:coollabsio/coolify.git';

    $source = Mockery::mock(GithubApp::class)->makePartial();
    $source->shouldReceive('getAttribute')->with('html_url')->andReturn('https://github.com');
    $application->source = $source;
    $application->shouldReceive('getAttribute')->with('source')->andReturn($source);

    $link = $application->gitCommitLink('sha123');
    
    // CURRENT BUG: https://github.com/github.com/coollabsio/coolify/commit/sha123 (nested domain)
    // EXPECTED: https://github.com/coollabsio/coolify/commit/sha123
    expect($link)->toBe('https://github.com/coollabsio/coolify/commit/sha123');
});

it('generates correct commit link for public SSH repo without source', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_repository = 'git@github.com:coollabsio/coolify.git';
    $application->source = null;
    $application->shouldReceive('getAttribute')->with('source')->andReturn(null);

    $link = $application->gitCommitLink('sha123');
    
    // CURRENT BUG: github.com/coollabsio/coolify/commit/sha123 (relative link, no https://)
    // EXPECTED: https://github.com/coollabsio/coolify/commit/sha123
    expect($link)->toBe('https://github.com/coollabsio/coolify/commit/sha123');
});

it('generates correct commit link for public HTTPS repo without source', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_repository = 'https://github.com/coollabsio/coolify';
    $application->source = null;
    $application->shouldReceive('getAttribute')->with('source')->andReturn(null);

    $link = $application->gitCommitLink('sha123');
    
    // CURRENT BUG: https://github.com/coollabsio/coolify (missing commit path)
    // EXPECTED: https://github.com/coollabsio/coolify/commit/sha123
    expect($link)->toBe('https://github.com/coollabsio/coolify/commit/sha123');
});

it('generates correct commit link for Bitbucket SSH repo', function () {
    $application = Mockery::mock(Application::class)->makePartial();
    $application->git_repository = 'git@bitbucket.org:user/repo.git';
    $application->source = null;
    $application->shouldReceive('getAttribute')->with('source')->andReturn(null);

    $link = $application->gitCommitLink('sha123');
    
    // EXPECTED: https://bitbucket.org/user/repo/commits/sha123 (Bitbucket uses /commits/ for single commits too)
    expect($link)->toBe('https://bitbucket.org/user/repo/commits/sha123');
});
