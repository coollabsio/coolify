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

    Livewire::test(SettingsDropdown::class, ['trigger' => 'changelog-sidebar'])
        ->call('openWhatsNewModal')
        ->assertSee('Changelog')
        ->assertSee('z-[60]', false)
        ->assertSee('closeWhatsNewModal', false);
});
