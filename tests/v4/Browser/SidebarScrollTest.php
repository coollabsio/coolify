<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-sidebar-scroll',
        'name' => 'Sidebar Scroll App',
    ]);
});

/**
 * @return array{bottom: float, viewport: int, overflows: bool, scrolled: bool}
 */
function measureSidebarScroll(mixed $page, string $scrollerSelector, string $frameSelector): array
{
    return $page->script(sprintf(<<<'JS'
        () => {
            const scroller = document.querySelector(%s);
            const frame = document.querySelector(%s);
            scroller.scrollTop = scroller.scrollHeight;

            return {
                bottom: frame.getBoundingClientRect().bottom,
                viewport: window.innerHeight,
                overflows: scroller.scrollHeight > scroller.clientHeight,
                scrolled: scroller.scrollTop > 0,
            };
        }
    JS, json_encode($scrollerSelector), json_encode($frameSelector)));
}

it('scrolls the main sidebar inside a short viewport', function () {
    loginAndSkipBoarding();

    $page = visit('/')->resize(1440, 420)->assertSee('Dashboard');

    $sidebar = measureSidebarScroll($page, 'div.lg\\:fixed nav > ul[role="list"]', 'div.lg\\:fixed nav');

    $page->screenshot(filename: 'main-sidebar-scroll');

    expect($sidebar['bottom'])->toBeLessThanOrEqual($sidebar['viewport'])
        ->and($sidebar['overflows'])->toBeTrue()
        ->and($sidebar['scrolled'])->toBeTrue();
});

it('scrolls the resource settings sidebar inside a short viewport', function () {
    loginAndSkipBoarding();

    $page = visit(applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->application
    ))->resize(1440, 420)->assertSee('Sidebar Scroll App');

    $sidebar = measureSidebarScroll($page, '.application-settings-navigation', '.application-settings-navigation');

    $page->screenshot(filename: 'settings-sidebar-scroll');

    expect($sidebar['bottom'])->toBeLessThanOrEqual($sidebar['viewport'])
        ->and($sidebar['overflows'])->toBeTrue()
        ->and($sidebar['scrolled'])->toBeTrue();
});
