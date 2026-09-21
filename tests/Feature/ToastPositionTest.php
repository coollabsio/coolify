<?php

use Symfony\Component\Process\Process;

test('toast messages default to the bottom right while supporting position overrides', function () {
    $toast = file_get_contents(resource_path('views/components/toast.blade.php'));

    expect($toast)
        ->toContain("position: options.position ?? 'bottom-right'")
        ->toContain("position: 'bottom-right'")
        ->toContain("this.position = event.detail.position || 'bottom-right'")
        ->toContain("'right-4 bottom-4 flex-col-reverse': position === 'bottom-right'")
        ->toContain("'left-1/2 top-4 -translate-x-1/2 flex-col': position === 'top-center'");
});

test('toast copy button shows temporary success feedback', function () {
    $toast = file_get_contents(resource_path('views/components/toast.blade.php'));

    expect($toast)
        ->toContain('copied: false')
        ->toContain('copyToast(toast)')
        ->toContain('toast.copied = true')
        ->toContain('toast.copied = false')
        ->toContain('}, 2000)')
        ->toContain('x-show="!toast.copied"')
        ->toContain('x-show="toast.copied"')
        ->toContain("'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400': toast.copied");
});

test('subscription success uses a persistent standard toast instead of a banner', function () {
    $view = file_get_contents(resource_path('views/livewire/layout-popups.blade.php'));
    if ($view === false) {
        throw new RuntimeException('Could not read layout popups view.');
    }

    if (preg_match("/@if \\(request\\(\\)->query->get\\('success'\\)\\)(.*?)@endif/s", $view, $match) !== 1 || ! isset($match[1])) {
        throw new RuntimeException('Could not locate the subscription success block.');
    }

    expect($match[1])->toContain("window.toast('Welcome onboard!'")
        ->toContain('persistent: true')
        ->not->toContain('<x-banner>');
});

test('persistent toasts survive timers hover and new notifications until dismissed', function () {
    $process = new Process(['node', '-e', <<<'JS'
const assert = require('node:assert/strict');
const fs = require('node:fs');
const source = fs.readFileSync(process.argv[1], 'utf8');
const data = source.match(/<ul x-data="([\s\S]*?)" @toast-show/)[1];
const timers = new Map();
let timerId = 0;
global.setTimeout = callback => { timers.set(++timerId, callback); return timerId; };
global.clearTimeout = id => timers.delete(id);
const state = new Function(`return (${data})`)();
state.$nextTick = callback => callback();
state.addToast({ detail: { message: 'Persistent', persistent: true } });
const persistent = state.toasts[0];
assert.equal(timers.size, 0, 'persistent toast must not schedule dismissal');
state.pauseToast(persistent);
state.resumeToast(persistent);
assert.equal(timers.size, 0, 'hover must not start a persistent toast timer');
for (let i = 0; i < 5; i++) state.addToast({ detail: { message: 'Normal' } });
assert(state.toasts.includes(persistent), 'new notifications must not evict persistent toast');
assert(timers.size > 0, 'normal notifications still have timers');
for (const callback of [...timers.values()]) callback();
for (const callback of [...timers.values()]) callback();
assert(state.toasts.includes(persistent), 'persistent toast survives elapsed timers');
state.removeToast(persistent.id);
for (const callback of [...timers.values()]) callback();
assert(!state.toasts.includes(persistent), 'manual dismissal removes persistent toast');
JS, resource_path('views/components/toast.blade.php')]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
