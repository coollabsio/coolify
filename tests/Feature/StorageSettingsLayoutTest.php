<?php

use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    InstanceSettings::forceCreate([
        'id' => 0,
        'fqdn' => null,
        'public_ipv4' => null,
        'public_ipv6' => null,
    ]);
});

it('places the storage name and connection status in breadcrumbs and delete in the danger zone', function () {
    $view = file_get_contents(resource_path('views/livewire/storage/show.blade.php'));
    $navbar = file_get_contents(resource_path('views/components/dashboard/navbar.blade.php'));

    expect($view)
        ->toContain(':mobileTitleOnly="true"')
        ->not->toContain('<x-slot:titleMeta>')
        ->toContain('storage.danger')
        ->toContain('Danger Zone')
        ->toContain('storage-danger-section')
        ->toContain("'label' => 'Resources'")
        ->toContain("'active' => \$currentRoute === 'storage.resources'")
        ->toContain('submitAction="delete"');

    expect(file_get_contents(app_path('Livewire/Storage/Form.php')))
        ->toContain("dispatch('storage-status-changed'");
    expect(file_get_contents(app_path('Livewire/Storage/Show.php')))
        ->toContain("#[On('storage-status-changed')]")
        ->toContain('$this->storage->refresh();');

    expect(file_get_contents(resource_path('views/components/top-breadcrumb.blade.php')))
        ->toContain('x-breadcrumb-switcher')
        ->toContain('$currentStorage->name')
        ->toContain("route('storage.show'")
        ->toContain("usable ? 'Connected' : 'Not usable'")
        ->toContain('@storage-status-changed.window');
    expect(file_get_contents(resource_path('views/components/breadcrumb-switcher.blade.php')))
        ->toContain('{{ $title }}')
        ->not->toContain('name="check-circle"');
    expect(file_get_contents(resource_path('views/livewire/storage/index.blade.php')))
        ->toContain('label="Connected"')
        ->not->toContain('label="Ready"');

    expect($navbar)
        ->toContain("request()->routeIs('storage.show', 'storage.danger', 'storage.resources')")
        ->not->toContain("['label' => 'Resources', 'route' => 'storage.resources'");

    expect(file_get_contents(base_path('routes/web.php')))
        ->toContain("->name('storage.danger')");
});

it('renders the danger zone page for a storage destination', function () {
    $storage = S3Storage::create([
        'uuid' => (string) str()->uuid(),
        'name' => 'minio',
        'description' => 'Local MinIO',
        'endpoint' => 'http://minio:9000',
        'bucket' => 'coolify',
        'region' => 'us-east-1',
        'key' => 'access',
        'secret' => 'secret',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]);

    $this->get(route('storage.danger', ['storage_uuid' => $storage->uuid]))
        ->assertOk()
        ->assertSee('Danger zone', false)
        ->assertSee('Delete storage', false)
        ->assertSee('Connected', false)
        ->assertDontSee('Validate connection', false);
});
