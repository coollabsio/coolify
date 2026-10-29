<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedBrowserResourceStack();
});

it('shows a permission helper popup above the sidebar', function () {
    loginAndSkipBoarding();

    $page = visit(route('security.api-tokens'))->resize(1024, 768)->assertSee('Selected permissions');

    $page->script(<<<'JS'
        () => [...document.querySelectorAll('button.listbox-trigger')]
            .find(button => button.textContent.includes('Selected permissions'))
            .click()
    JS);
    $page->wait(0.5);

    $page->script(<<<'JS'
        () => document.querySelector('label[for^="permission-root"] .info-helper')
            .dispatchEvent(new MouseEvent('mouseenter'))
    JS);
    $page->wait(0.5);

    $state = $page->script(<<<'JS'
        () => {
            const popup = [...document.querySelectorAll('.info-helper-popup')]
                .find(element => element.textContent.includes('Full access to every API operation'));
            const rect = popup.getBoundingClientRect();
            const points = [
                [rect.left + 4, rect.top + 4],
                [rect.left + rect.width / 2, rect.top + rect.height / 2],
                [rect.right - 4, rect.bottom - 4],
            ];

            return {
                visible: rect.width > 0 && rect.height > 0,
                onTop: points.every(([x, y]) => popup.contains(document.elementFromPoint(x, y))),
            };
        }
    JS);

    $page->screenshot(filename: 'api-token-permission-helper');

    expect($state)->toBe(['visible' => true, 'onTop' => true]);
});
