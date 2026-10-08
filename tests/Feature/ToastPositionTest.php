<?php

use Symfony\Component\Process\Process;

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
