<?php

use App\Http\Controllers\FileBrowserDownloadController;
use App\Jobs\PrepareFileDownloadJob;
use App\Livewire\Project\Shared\FileBrowser;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\ContainerFilesystemService;
use App\Services\FileDownloadStorageService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('ssh-keys');
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);
    $this->environment = $this->project->environments()->first();
});

it('blocks non-admin members from the file browser', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $application])
        ->assertForbidden();
});

it('shows a stopped state when the container is not running', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'exited',
    ]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $application])
        ->assertSet('containerRunning', false)
        ->assertSee('Start the container to browse its files');
});

it('loads the default directory in a deferred request for a running database', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/var/lib/postgresql'))   // defaultRoot inspect
        ->push(Process::result(output: "dir\t0\t1\tdata\nfile\t10\t2\tpostgresql.conf"))]); // list

    $database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test DB',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'image' => 'postgres:15',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->assertSet('containerRunning', true)
        ->assertSet('currentPath', '/var/lib/postgresql')
        ->assertSet('container', $database->uuid)
        ->assertSee('data')
        ->assertSee('postgresql.conf');
});

function fbRunningDatabase($environment, $destination): StandalonePostgresql
{
    return StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'DB',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'image' => 'postgres:15',
        'status' => 'running',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function fbNoShellResult(): ProcessResult
{
    return Process::result(
        errorOutput: 'OCI runtime exec failed: exec failed: unable to start container process: exec: "sh": executable file not found in $PATH',
        exitCode: 127,
    );
}

function fbRootProcessStatus(): string
{
    return "Uid:\t0\t0\t0\t0\nGid:\t0\t0\t0\t0\nGroups:\t0\nCapPrm:\t00000000a80425fb\n";
}

it('browses a container without a shell through the helper container', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/'))                  // defaultRoot inspect
        ->push(fbNoShellResult())                              // docker exec list
        ->push(Process::result(output: fbRootProcessStatus())) // helper identity
        ->push(Process::result(output: "directory\t0\t1\t755\troot\troot\t./data\nsymbolic link\t4\t1\t777\troot\troot\t./etc-link"))]); // helper list

    $database = fbRunningDatabase($this->environment, $this->destination);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->assertSet('useHelper', true)
        ->assertSet('shellUnavailable', false)
        ->assertSee('data')
        ->assertSee('Upload a file to this folder')
        ->call('open', 'etc-link')
        ->assertDispatched('error', 'Symlinks cannot be opened in a container without a shell. Open the target path instead.')
        ->assertSet('currentPath', '/');
});

it('explains when neither the shell nor the helper container can open the files', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/'))
        ->push(fbNoShellResult())
        ->push(Process::result(output: fbRootProcessStatus()))
        ->push(Process::result(errorOutput: 'Unable to find image', exitCode: 125))]);

    $database = fbRunningDatabase($this->environment, $this->destination);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->assertSet('loadFailed', false)
        ->assertSet('shellUnavailable', true)
        ->assertSee('Coolify cannot open the files of this container.')
        ->assertSee('The server could not pull the Coolify helper image')
        ->assertDontSee('This folder is empty')
        ->assertDontSee('Upload a file to this folder');
});

it('keeps helper mode in the download token', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/'))
        ->push(fbNoShellResult())
        ->push(Process::result(output: fbRootProcessStatus()))
        ->push(Process::result(output: "directory\t0\t1\t755\troot\troot\t./data"))
        ->push(Process::result(output: fbRootProcessStatus()))
        ->push(Process::result(output: 'dir 4'))]);

    $database = fbRunningDatabase($this->environment, $this->destination);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('download', 'data');

    $token = Str::afterLast($component->effects['redirect'], '/');
    expect(Cache::get(FileBrowserDownloadController::cacheKey($token)))->toMatchArray(['use_helper' => true, 'path' => '/data']);
});

it('creates a directory and re-lists', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))                    // defaultRoot
        ->push(Process::result(output: ''))                          // initial list (empty)
        ->push(Process::result(output: ''))                          // mkdir
        ->push(Process::result(output: "dir\t0\t1\tplugins"))]);     // re-list

    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('createDirectory', 'plugins')
        ->assertSee('plugins');
});

it('creates a file and re-lists', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))                     // defaultRoot
        ->push(Process::result(output: ''))                           // initial list (empty)
        ->push(Process::result(output: ''))                           // createFile
        ->push(Process::result(output: "file\t0\t1\t644\tconfig.yml"))]); // re-list

    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('createFile', 'config.yml')
        ->assertSee('config.yml');
});

it('changes permissions and re-lists', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))                        // defaultRoot
        ->push(Process::result(output: "file\t10\t1\t644\tapp.sh"))     // initial list
        ->push(Process::result(output: ''))                             // chmod
        ->push(Process::result(output: "file\t10\t1\t755\tapp.sh"))]);  // re-list

    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->assertSee('644')
        ->call('chmodEntry', 'app.sh', '755')
        ->assertSee('755');
});

it('sets the editor language and content from the file when opening', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))                       // defaultRoot
        ->push(Process::result(output: "file\t12\t1\t644\tconfig.yml")) // initial list
        ->push(Process::result(output: "OK\n".base64_encode("a: 1\n")))]); // single-round-trip read

    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('openEditor', 'config.yml')
        ->assertSet('editorOpen', true)
        ->assertSet('editorLanguage', 'yaml')
        ->assertSet('editorContent', "a: 1\n")
        ->assertDispatched('load-file-editor', content: "a: 1\n", language: 'yaml');
});

it('resolves a Monaco language for common extension-less files', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))                      // defaultRoot
        ->push(Process::result(output: "file\t12\t1\t644\tDockerfile")) // initial list
        ->push(Process::result(output: "OK\n".base64_encode("FROM alpine\n")))]); // read

    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('openEditor', 'Dockerfile')
        ->assertSet('editorLanguage', 'dockerfile');
});

it('refuses to open a binary or oversized file in the editor', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))                     // defaultRoot
        ->push(Process::result(output: "file\t99999999\t1\tbig.bin")) // initial list
        ->push(Process::result(output: 'TOOBIG'))]);                 // single-round-trip read

    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('openEditor', 'big.bin')
        ->assertSet('editingPath', null);
});

it('rejects client changes to container access state', function (string $property, mixed $value) {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))   // defaultRoot
        ->push(Process::result(output: ''))]);      // initial list

    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->set($property, $value);
})->with([
    ['container', 'someone-elses-container'],
    ['type', 'service'],
    ['containerRunning', false],
    ['availableContainers', ['someone-elses-container']],
])->throws(CannotUpdateLockedPropertyException::class);

it('rechecks resource access before file operations', function (string $action, array $arguments) {
    Process::fake(['*' => Process::result(output: '')]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $component = Livewire::test(FileBrowser::class, ['resource' => $database])->call('loadFiles');
    $otherTeam = Team::factory()->create();
    $this->project->update(['team_id' => $otherTeam->id]);
    $component->call($action, ...$arguments)->assertForbidden();
})->with([
    ['open', ['folder']],
    ['goTo', ['/data']],
    ['refresh', []],
    ['loadFiles', []],
    ['selectContainer', ['unknown']],
    ['saveEditor', []],
    ['deleteEntry', ['config.yml']],
    ['download', ['config.yml']],
]);

it('rejects a client attempt to replace the editor path', function () {
    Process::fake(['*' => Process::result(output: '')]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->set('editingPath', '/etc/passwd');
})->throws(CannotUpdateLockedPropertyException::class);

it('rejects unsafe child names without deleting the current folder', function (string $name) {
    Process::fake(['*' => Process::result(output: '')]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('deleteEntry', $name)
        ->assertDispatched('error');
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'rm -rf'));
})->with(['', '.', '..', '../data', 'a/b', 'a\\b']);

it('saves the opened file and clears editor state', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))
        ->push(Process::result(output: ''))
        ->push(Process::result(output: "OK\n".base64_encode('before')))
        ->push(Process::result(output: ''))
        ->push(Process::result(output: ''))]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('openEditor', "it's.txt")
        ->set('editorContent', 'after')
        ->call('saveEditor')
        ->assertSet('editingPath', null)
        ->assertSet('editorOpen', false)
        ->assertSet('editorContent', '')
        ->assertDispatched('success', 'Saved.');
});

it('clears the editor when selecting a container', function () {
    Process::fake(['*' => Process::result(output: 'OK')]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('openEditor', 'config.yml')
        ->assertSet('editorOpen', true)
        ->call('selectContainer', $database->uuid)
        ->assertSet('editingPath', null)
        ->assertSet('editorOpen', false);
});

it('renames and deletes files with apostrophes', function () {
    Process::fake(['*' => Process::result(output: '')]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('renameEntry', "it's.txt", "that's.txt")
        ->assertDispatched('success', 'Renamed.')
        ->call('deleteEntry', "that's.txt")
        ->assertDispatched('success', 'Deleted.');
});

it('uploads a file and clears the upload state', function () {
    Process::fake(['*' => Process::result(output: '')]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->set('upload', UploadedFile::fake()->create("it's.txt", 1, 'text/plain'))
        ->call('uploadFile')
        ->assertSet('upload', null)
        ->assertDispatched('success', 'Uploaded.');
});

it('issues a one-time download token and redirects to the streaming route', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))
        ->push(Process::result(output: ''))
        ->push(Process::result(output: 'dir 4'))]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('download', 'folder')
        ->assertRedirectContains('/files/download/');

    $token = Str::afterLast($component->effects['redirect'], '/');
    expect(Cache::get(FileBrowserDownloadController::cacheKey($token)))->toMatchArray([
        'user_id' => $this->admin->id,
        'container' => $database->uuid,
        'path' => '/data/folder',
        'name' => 'folder.tar.gz',
        'is_dir' => true,
    ]);
});

it('refuses downloads above the size cap', function () {
    $overCapKb = intdiv(ContainerFilesystemService::MAX_DOWNLOAD_SIZE, 1024) + 1;
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))
        ->push(Process::result(output: ''))
        ->push(Process::result(output: "dir {$overCapKb}"))]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('download', 'folder')
        ->assertNoRedirect()
        ->assertDispatched('error');
});

it('only lets the token owner use a download token, and only once', function () {
    $database = fbRunningDatabase($this->environment, $this->destination);
    $download = [
        'user_id' => $this->admin->id,
        'resource_type' => $database::class,
        'resource_id' => $database->id,
        'server_id' => $this->server->id,
        'container' => $database->uuid,
        'path' => '/data/a.txt',
        'name' => 'a.txt',
        'is_dir' => false,
    ];
    $otherAdmin = User::factory()->create();
    $otherAdmin->teams()->attach($this->team, ['role' => 'admin']);

    Cache::put(FileBrowserDownloadController::cacheKey('token-a'), $download, 60);
    $this->actingAs($otherAdmin)->withSession(['currentTeam' => $this->team])
        ->get(route('project.files.download', 'token-a'))
        ->assertNotFound();
    expect(Cache::has(FileBrowserDownloadController::cacheKey('token-a')))->toBeFalse();

    $this->actingAs($this->admin)->withSession(['currentTeam' => $this->team])
        ->get(route('project.files.download', 'unknown'))
        ->assertNotFound();
});

it('blocks members from the download route', function () {
    $this->actingAs($this->member)->withSession(['currentTeam' => $this->team])
        ->get(route('project.files.download', 'any'))
        ->assertForbidden();
});

it('denies resources outside the current team even when the user belongs to both teams', function () {
    $database = fbRunningDatabase($this->environment, $this->destination);
    $otherTeam = Team::factory()->create();
    $this->member->teams()->attach($otherTeam, ['role' => 'admin']);
    $this->actingAs($this->member);
    session(['currentTeam' => $otherTeam]);
    Process::fake();
    Livewire::test(FileBrowser::class, ['resource' => $database])->assertForbidden();
    Process::assertNothingRan();
});

it('does not use a container that stopped after mounting', function (string $action, array $arguments) {
    Process::fake(['*' => Process::result(output: '')]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $component = Livewire::test(FileBrowser::class, ['resource' => $database])->call('loadFiles');
    $database->update(['status' => 'exited']);
    $component->call($action, ...$arguments);
})->with([
    ['open', ['folder']],
    ['goTo', ['/data']],
    ['refresh', []],
])->throws(RuntimeException::class, 'Container is not running.');

it('lists files for a running application and excludes stopped and preview containers', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running',
    ]);
    $containers = collect([
        ['ID' => 'base', 'Names' => 'app-base', 'State' => 'running', 'Labels' => 'coolify.pullRequestId=0'],
        ['ID' => 'stopped', 'Names' => 'app-stopped', 'State' => 'exited', 'Labels' => 'coolify.pullRequestId=0'],
        ['ID' => 'preview', 'Names' => 'app-preview', 'State' => 'running', 'Labels' => 'coolify.pullRequestId=42'],
    ])->map(fn ($container) => json_encode($container))->implode("\n");
    Process::fake(function ($process) use ($containers) {
        $output = match (true) {
            str_contains($process->command, 'docker ps') => $containers,
            str_contains($process->command, 'docker inspect') => '/app',
            default => "file\t12\t1\t644\tconfig.yml",
        };

        return Process::result(output: $output);
    });
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $application])
        ->call('loadFiles')
        ->assertSet('availableContainers', ['app-base'])
        ->assertSet('container', 'app-base')
        ->assertSet('currentPath', '/app')
        ->assertSee('config.yml')
        ->call('refresh')
        ->assertSet('container', 'app-base')
        ->assertSee('config.yml');
});

it('renders before SSH starts and loads files in a later request', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))
        ->push(Process::result(output: "file\t0\t1\t644\tconfig.yml"))]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(FileBrowser::class, ['resource' => $database]);
    Process::assertNothingRan();
    $component->assertSet('filesLoaded', false)
        ->assertSet('container', null)
        ->call('loadFiles')
        ->assertSet('filesLoaded', true)
        ->assertSet('currentPath', '/data')
        ->assertSee('config.yml');
});

it('does not start SSH if the resource stops before deferred loading', function () {
    Process::fake();
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $component = Livewire::test(FileBrowser::class, ['resource' => $database]);
    $database->update(['status' => 'exited']);

    $component->call('loadFiles')
        ->assertSet('filesLoaded', true)
        ->assertSet('containerRunning', false);
    Process::assertNothingRan();
});

it('allows retrying a failed initial container lookup', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running',
    ]);
    $fail = true;
    Process::fake(function ($process) use (&$fail) {
        if (str_contains($process->command, 'docker ps')) {
            if ($fail) {
                $fail = false;

                return Process::result(errorOutput: 'Permission denied', exitCode: 1);
            }

            return Process::result(output: json_encode([
                'ID' => 'base', 'Names' => 'app-base', 'State' => 'running', 'Labels' => '',
            ]));
        }

        return Process::result(output: str_contains($process->command, 'docker inspect') ? '/app' : '');
    });
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $application])
        ->call('loadFiles')
        ->assertSet('filesLoaded', false)
        ->assertSet('loadFailed', true)
        ->assertSet('container', null)
        ->call('loadFiles')
        ->assertSet('filesLoaded', true)
        ->assertSet('loadFailed', false)
        ->assertSet('container', 'app-base');
});

function fbConfigureDownloadStorage(): S3Storage
{
    Team::factory()->create(['id' => 0]);
    $storage = S3Storage::create([
        'name' => 'Downloads',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'downloads',
        'endpoint' => 'https://s3.example.com',
        'is_usable' => true,
        'team_id' => 0,
    ]);
    InstanceSettings::find(0)->update(['file_download_s3_storage_id' => $storage->id]);
    Once::flush();

    return $storage;
}

it('queues an S3 upload instead of streaming when download storage is set', function () {
    Bus::fake([PrepareFileDownloadJob::class]);
    $storage = fbConfigureDownloadStorage();
    $overStreamCapKb = intdiv(ContainerFilesystemService::MAX_DOWNLOAD_SIZE, 1024) + 1;
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))
        ->push(Process::result(output: ''))
        ->push(Process::result(output: "dir {$overStreamCapKb}"))]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('download', 'folder')
        ->assertNoRedirect()
        ->assertDispatched('info');

    expect($component->get('pendingDownloads'))->toHaveCount(1);
    Bus::assertDispatched(PrepareFileDownloadJob::class, fn (PrepareFileDownloadJob $job) => $job->downloadId === $component->get('pendingDownloads')[0]
        && $job->userId === $this->admin->id
        && $job->path === '/data/folder'
        && $job->isDir
        && $job->downloadName === 'folder.tar.gz'
        && $job->s3StorageId === $storage->id);
});

it('refuses S3 downloads above the S3 size cap', function () {
    Bus::fake([PrepareFileDownloadJob::class]);
    fbConfigureDownloadStorage();
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))
        ->push(Process::result(output: ''))
        ->push(Process::result(output: 'file '.(FileDownloadStorageService::MAX_SIZE + 1)))]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('download', 'big.bin')
        ->assertDispatched('error');
    Bus::assertNotDispatched(PrepareFileDownloadJob::class);
});

it('opens a finished S3 download once and ignores entries of other users', function () {
    Bus::fake([PrepareFileDownloadJob::class]);
    fbConfigureDownloadStorage();
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))
        ->push(Process::result(output: ''))
        ->push(Process::result(output: 'file 10'))]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $component = Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('download', 'a.txt');
    $downloadId = $component->get('pendingDownloads')[0];
    $cacheKey = PrepareFileDownloadJob::cacheKey($downloadId);

    Cache::put($cacheKey, ['user_id' => $this->member->id, 'status' => 'ready', 'url' => 'https://s3.example.com/other'], 60);
    $component->call('checkDownloads')
        ->assertNotDispatched('open-file-download')
        ->assertSet('pendingDownloads', [$downloadId]);

    Cache::put($cacheKey, ['user_id' => $this->admin->id, 'status' => 'ready', 'url' => 'https://s3.example.com/get'], 60);
    $component->call('checkDownloads')
        ->assertDispatched('open-file-download', url: 'https://s3.example.com/get')
        ->assertSet('pendingDownloads', []);
    expect(Cache::has($cacheKey))->toBeFalse();
});

it('does not hand out S3 links after the user lost access to the resource', function () {
    Bus::fake([PrepareFileDownloadJob::class]);
    fbConfigureDownloadStorage();
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))
        ->push(Process::result(output: ''))
        ->push(Process::result(output: 'file 10'))]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $component = Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('download', 'a.txt');
    Cache::put(PrepareFileDownloadJob::cacheKey($component->get('pendingDownloads')[0]), ['user_id' => $this->admin->id, 'status' => 'ready', 'url' => 'https://s3.example.com/get'], 60);

    $this->project->update(['team_id' => Team::factory()->create()->id]);

    $component->call('checkDownloads')
        ->assertForbidden()
        ->assertNotDispatched('open-file-download');
});

it('limits how many S3 downloads a user can start', function () {
    Bus::fake([PrepareFileDownloadJob::class]);
    fbConfigureDownloadStorage();
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))
        ->push(Process::result(output: ''))
        ->push(Process::result(output: 'file 10'))
        ->push(Process::result(output: 'file 10'))
        ->push(Process::result(output: 'file 10'))
        ->push(Process::result(output: 'file 10'))]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $component = Livewire::test(FileBrowser::class, ['resource' => $database])->call('loadFiles');

    foreach (range(1, 4) as $attempt) {
        $component->call('download', 'a.txt');
    }

    expect($component->get('pendingDownloads'))->toHaveCount(3);
    Bus::assertDispatchedTimes(PrepareFileDownloadJob::class, 3);
    $component->assertDispatched('error', 'Too many downloads are in progress. Wait for them to finish, then try again.');
});

it('blocks navigation before a container is selected', function (string $action, array $arguments) {
    Process::fake();
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $component = Livewire::test(FileBrowser::class, ['resource' => $database]);

    expect(fn () => $component->call($action, ...$arguments))
        ->toThrow(RuntimeException::class, 'Container is not running.');
    expect($component->instance()->currentPath)->toBe('');
    Process::assertNothingRan();
})->with([
    ['open', ['folder']],
    ['goTo', ['/data']],
    ['refresh', []],
]);

it('reduces upload names to one filename component', function (string $name, string $expected) {
    $component = new FileBrowser;
    $sanitize = new ReflectionMethod(FileBrowser::class, 'sanitizeUploadName');

    expect($sanitize->invoke($component, $name))->toBe($expected);
})->with([
    ['../../etc/cron.d/payload', 'payload'],
    ['..\\..\\etc\\cron.d\\payload', 'payload'],
    ['../nested\\folder/payload', 'payload'],
    ["it's.txt", "it's.txt"],
    ['.env', '.env'],
    ['my file.txt', 'my file.txt'],
]);

it('rejects empty and all-dot upload names', function (string $name) {
    $component = new FileBrowser;
    $sanitize = new ReflectionMethod(FileBrowser::class, 'sanitizeUploadName');

    expect(fn () => $sanitize->invoke($component, $name))
        ->toThrow(RuntimeException::class, 'Invalid file name.');
})->with(['', '/', '\\', '.', '..', '...', '../..', '..\\..']);

it('refuses to save an opened file after its container identity changes', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/data'))
        ->push(Process::result(output: ''))
        ->push(Process::result(output: "OK\n".base64_encode('before')))]);
    $database = fbRunningDatabase($this->environment, $this->destination);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $component = Livewire::test(FileBrowser::class, ['resource' => $database])
        ->call('loadFiles')
        ->call('openEditor', 'config.yml')
        ->set('editorContent', 'after');

    $database->update(['uuid' => (string) Str::uuid()]);
    Process::swap(new Factory);
    Process::fake();

    expect(fn () => $component->call('saveEditor'))
        ->toThrow(RuntimeException::class, 'Container is not running.');
    expect($component->instance()->editingPath)->toBe('/data/config.yml');
    Process::assertNothingRan();
});
