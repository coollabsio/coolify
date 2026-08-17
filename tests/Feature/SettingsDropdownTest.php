<?php

use App\Livewire\SettingsDropdown;
use App\Models\User;
use App\Services\ChangelogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

it('renders the changelog modal above the desktop sidebar toggle', function () {
    $user = new User(['email' => 'test@example.com']);
    $user->id = 1;

    Auth::setUser($user);

    app()->instance(ChangelogService::class, new class extends ChangelogService
    {
        public function getEntriesForUser(User $user): Collection
        {
            return collect([
                (object) [
                    'tag_name' => 'v1.0.0',
                    'title' => 'Test Release',
                    'content' => 'Release notes',
                    'content_html' => '<p>Release notes</p>',
                    'published_at' => Carbon::parse('2026-05-01'),
                    'is_read' => false,
                ],
            ]);
        }

        public function getUnreadCountForUser(User $user): int
        {
            return 1;
        }
    });

    $component = Livewire::test(SettingsDropdown::class, ['trigger' => 'changelog-sidebar'])
        ->call('openWhatsNewModal')
        ->assertNotDispatched('whats-new-opened')
        ->assertSee("What's new", false)
        ->assertSee('Test Release')
        ->assertSee('Mark read')
        ->assertSeeHtml('z-[99]')
        ->assertSeeHtml('closeWhatsNewModal');

    // Single-release layout: no nested card chrome / unread left accent bar
    expect($component->html())
        ->not->toContain('dark:bg-raised')
        ->not->toContain('w-0.5 bg-accent')
        ->toContain('absolute right-2 top-2');
});

it('keeps the account menu mounted while the changelog modal opens', function () {
    $dropdownView = file_get_contents(resource_path('views/livewire/settings-dropdown.blade.php'));
    $accountMenuView = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($dropdownView)
        ->not->toContain('wire:click="openWhatsNewModal" @click="open = false"')
        ->and($accountMenuView)
        ->not->toContain('@whats-new-opened.window="open = false"');
});

it('opens the changelog modal when there are no entries', function () {
    $user = new User(['email' => 'test@example.com']);
    $user->id = 1;

    Auth::setUser($user);

    app()->instance(ChangelogService::class, new class extends ChangelogService
    {
        public function getEntriesForUser(User $user): Collection
        {
            return collect();
        }

        public function getUnreadCountForUser(User $user): int
        {
            return 0;
        }
    });

    Livewire::test(SettingsDropdown::class, ['trigger' => 'account-menu'])
        ->call('openWhatsNewModal')
        ->assertSet('showWhatsNewModal', true)
        ->assertSee("What's new", false)
        ->assertSee('No updates found');
});
