<?php

use App\Livewire\Team\Invitations;
use App\Livewire\Team\InviteLink;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['fqdn' => null]));

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

it('shows generate link with loading state targeting viaLink', function () {
    $view = file_get_contents(resource_path('views/livewire/team/invite-link.blade.php'));

    expect($view)
        ->toContain('wire:submit="viaLink"')
        ->toContain('wire:target="viaLink"')
        ->toContain('<x-forms.button type="submit" wire:target="viaLink"')
        ->toContain('Generate link');

    Livewire::test(InviteLink::class)
        ->assertSee('Generate link')
        ->assertSeeHtml('wire:target="viaLink"')
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('wire:loading.class="is-loading"');
});

it('renders a real copy button for pending invitation links', function () {
    $invitation = TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => 'test-invitation-uuid-123456789012',
        'email' => 'invitee@example.com',
        'role' => 'member',
        'link' => 'http://example.test/invitations/test-invitation-uuid-123456789012',
        'via' => 'link',
    ]);

    $view = file_get_contents(resource_path('views/livewire/team/invitations.blade.php'));

    expect($view)
        ->toContain('aria-label="Copy invitation link"')
        ->toContain('window.copyToClipboard(@js($invite->link))')
        ->toContain('class="button h-7! shrink-0 px-2!"');

    Livewire::test(Invitations::class, [
        'invitations' => TeamInvitation::ownedByCurrentTeam()->get(),
    ])
        ->assertSee($invitation->link)
        ->assertSeeHtml('aria-label="Copy invitation link"')
        ->assertSeeHtml('window.copyToClipboard(')
        ->assertSeeHtml('type="button"');
});

it('exposes a resilient global copyToClipboard helper', function () {
    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));

    expect($layout)
        ->toContain('async function copyToClipboard(text)')
        ->toContain('window.copyToClipboard = copyToClipboard')
        ->toContain('document.execCommand(\'copy\')')
        ->toContain('window.isSecureContext');
});
