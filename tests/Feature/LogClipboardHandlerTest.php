<?php

use Illuminate\Support\Str;

function bladeView(string $path): string
{
    return file_get_contents(base_path($path));
}

it('guards deployment log clipboard writes and reports promise failures', function () {
    $view = bladeView('resources/views/livewire/project/application/deployment/show.blade.php');

    expect($view)
        ->toContain('copyLogs()')
        ->toContain('navigator.clipboard?.writeText')
        ->toContain("Livewire.dispatch('error', ['Clipboard is not available. Please use HTTPS or localhost.']);")
        ->toContain("Livewire.dispatch('error', ['Failed to copy logs to clipboard.']);")
        ->toContain("Livewire.dispatch('success', ['Logs copied to clipboard.']);");

    expect(Str::between($view, 'copyLogs() {', 'downloadLogs()'))
        ->toContain('navigator.clipboard?.writeText(content).then(() =>')
        ->not->toContain("navigator.clipboard.writeText(content);\n            Livewire.dispatch('success'");
});

it('guards shared log clipboard writes and handles Livewire preparation failures', function () {
    $view = bladeView('resources/views/livewire/project/shared/get-logs.blade.php');

    expect($view)
        ->toContain('navigator.clipboard?.writeText')
        ->toContain("Livewire.dispatch('error', ['Clipboard is not available. Please use HTTPS or localhost.']);")
        ->toContain("Livewire.dispatch('error', ['Failed to copy logs to clipboard.']);")
        ->toContain("Livewire.dispatch('error', ['Failed to prepare logs for clipboard.']);")
        ->toContain("Livewire.dispatch('success', ['Logs copied to clipboard.']);");

    expect($view)
        ->toContain('$wire.copyLogs().then(logs =>')
        ->toContain('}).catch(() => {')
        ->not->toContain('navigator.clipboard.writeText(logs);');
});
