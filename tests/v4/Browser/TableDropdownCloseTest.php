<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('closes the table dropdown without calling window.close when an option is clicked', function () {
    $stack = seedBrowserResourceStack();

    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->navigate(route('project.resource.create', [
            'project_uuid' => $stack['project']->uuid,
            'environment_uuid' => $stack['environment']->uuid,
        ]))
        ->assertSee('Filter');

    $page->script(<<<'JS'
        () => {
            window.__windowCloseCalls = 0;
            window.close = () => window.__windowCloseCalls++;
            [...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Filter').click();
        }
    JS);
    $page->wait(0.5);

    $page->script(<<<'JS'
        () => [...document.querySelectorAll('[role="option"]')]
            .find(option => option.textContent.trim() === 'Applications')
            .click()
    JS);
    $page->wait(0.5);

    $state = $page->script(<<<'JS'
        () => {
            const trigger = [...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Filter');

            return {
                windowCloseCalls: window.__windowCloseCalls,
                open: Alpine.$data(trigger.closest('[x-data]')).open,
            };
        }
    JS);

    expect($state)->toBe(['windowCloseCalls' => 0, 'open' => false]);
    $page->screenshot(filename: 'table-dropdown-close');
});
