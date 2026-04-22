<?php

use Illuminate\Support\Collection;

it('renders changelog modal with an initial entry limit and show more control', function () {
    $html = view('livewire.settings-dropdown', [
        'entries' => new Collection,
        'unreadCount' => 0,
        'currentVersion' => 'v4.0.0-beta.999',
        'showWhatsNewModal' => true,
    ])->render();

    expect($html)
        ->toContain('visibleEntryCount: 10')
        ->toContain('return this.filteredEntries.slice(0, this.visibleEntryCount);')
        ->toContain('this.visibleEntryCount += 10;')
        ->toContain('Show 10 more')
        ->toContain('View full changelog on GitHub');
});
