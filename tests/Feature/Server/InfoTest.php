<?php

namespace Tests\Feature\Server;

use App\Jobs\CollectServerInfoJob;
use App\Livewire\Server\Info;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class InfoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create instance settings
        InstanceSettings::factory()->create();

        // Create a user and team
        $this->user = User::factory()->create();
        $this->team = Team::factory()->create();

        // Attach the user to the team
        $this->user->teams()->attach($this->team);

        // Act as the user
        $this->actingAs($this->user);
    }

    // Helper to mock the currentTeam method
    protected function mockCurrentTeam()
    {
        // Mock the User model's currentTeam method to return our team
        $this->user->shouldReceive('currentTeam')->andReturn($this->team);

        // Or optionally, mock auth()->user()->currentTeam() directly
        // This depends on how your application is structured
        $this->mock('alias:Illuminate\Support\Facades\Auth', function ($mock) {
            $mock->shouldReceive('user')->andReturn($this->user);
        });
    }

    public function test_info_component_renders_correctly()
    {
        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Server',
        ]);

        // Create server settings with some data
        ServerSetting::factory()->create([
            'server_id' => $server->id,
            'cpu_model' => 'Intel(R) Xeon(R) CPU @ 2.20GHz',
            'cpu_cores' => 4,
            'memory_total' => '16G',
            'disk_total' => '100G',
            'os_name' => 'Ubuntu',
        ]);

        // Test that the component renders correctly
        Livewire::test(Info::class, ['server_uuid' => $server->uuid])
            ->assertSee('Server Information')
            ->assertSee('CPU Information')
            ->assertSee('Memory Information')
            ->assertSee('Disk Information')
            ->assertSee('GPU Information')
            ->assertSee('Operating System Information')
            ->assertSee('Intel(R) Xeon(R) CPU @ 2.20GHz')
            ->assertSee('16G')
            ->assertSee('100G')
            ->assertSee('Ubuntu');
    }

    public function test_collect_server_info_dispatches_job()
    {
        // Set up queue fake
        Queue::fake();

        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Server',
        ]);

        // Create server settings
        ServerSetting::factory()->create([
            'server_id' => $server->id,
        ]);

        // Test that the collectServerInfo method dispatches the job
        Livewire::test(Info::class, ['server_uuid' => $server->uuid])
            ->call('collectServerInfo');

        // Assert that the job was dispatched
        Queue::assertPushed(CollectServerInfoJob::class, function ($job) use ($server) {
            return $job->server->id === $server->id;
        });
    }

    public function test_refresh_method_updates_component()
    {
        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Server',
        ]);

        // Create server settings
        ServerSetting::factory()->create([
            'server_id' => $server->id,
        ]);

        // Test that the refresh method updates the component
        $component = Livewire::test(Info::class, ['server_uuid' => $server->uuid]);

        // Set isCollecting to true
        $component->set('isCollecting', true);

        // Call refresh
        $component->call('refresh');

        // Assert that isCollecting is reset to false
        $component->assertSet('isCollecting', false);

        // Assert that a notification is dispatched
        $component->assertDispatched('notify');
    }

    public function test_auto_collect_on_mount_if_no_info()
    {
        // Set up queue fake
        Queue::fake();

        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Server',
        ]);

        // Create server settings with no info
        ServerSetting::factory()->create([
            'server_id' => $server->id,
            'cpu_model' => null,
            'memory_total' => null,
            'disk_total' => null,
            'os_name' => null,
        ]);

        // Mount the component
        Livewire::test(Info::class, ['server_uuid' => $server->uuid]);

        // Assert that the job was dispatched automatically
        Queue::assertPushed(CollectServerInfoJob::class, function ($job) use ($server) {
            return $job->server->id === $server->id;
        });
    }

    public function test_no_auto_collect_on_mount_if_info_exists()
    {
        // Set up queue fake
        Queue::fake();

        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Server',
        ]);

        // Create server settings with some info
        ServerSetting::factory()->create([
            'server_id' => $server->id,
            'cpu_model' => 'Intel(R) Xeon(R) CPU @ 2.20GHz',
            'memory_total' => null,
            'disk_total' => null,
            'os_name' => null,
        ]);

        // Mount the component
        Livewire::test(Info::class, ['server_uuid' => $server->uuid]);

        // Assert that the job was not dispatched automatically
        Queue::assertNotPushed(CollectServerInfoJob::class);
    }
}
