<?php

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('quick adds an existing tag whose name contains an apostrophe', function () {
    $stack = seedBrowserResourceStack();
    $source = createBrowserApplication($stack, ['name' => 'Source App']);
    $target = createBrowserApplication($stack, ['name' => 'Target App']);
    $source->tags()->attach($tag = Tag::create(['name' => "o'reilly", 'team_id' => 0]));

    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->navigate(route('project.application.tags', [
            'project_uuid' => $stack['project']->uuid,
            'environment_uuid' => $stack['environment']->uuid,
            'application_uuid' => $target->uuid,
        ]))
        ->assertSee("o'reilly");

    $page->script(<<<'JS'
        () => [...document.querySelectorAll('#available-tags-section button')]
            .find(button => button.textContent.includes('reilly'))
            .click()
    JS);
    $page->wait(1.5);

    expect($target->tags()->whereKey($tag->id)->exists())->toBeTrue();
    $page->screenshot(filename: 'tag-quick-add');
});
