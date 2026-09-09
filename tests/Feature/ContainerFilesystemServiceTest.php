<?php

use App\Data\FileEntry;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\ContainerFilesystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
    // Disable SSH multiplexing so each instant_remote_process is exactly one
    // faked Process call (mux setup would run extra calls and desync sequences).
    config(['constants.ssh.mux_enabled' => false]);
});

function fsService(): ContainerFilesystemService
{
    $server = Server::factory()->make(['id' => 999]);

    return new ContainerFilesystemService($server, 'app-123');
}

function fsServer(): Server
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    return Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.10',
    ]);
}

it('sorts directories before files, then by name case-insensitively', function () {
    $entries = [
        new FileEntry('README.md', 'file', 10, 100),
        new FileEntry('src', 'dir', 0, 100),
        new FileEntry('.env', 'file', 5, 100),
        new FileEntry('assets', 'dir', 0, 100),
    ];

    $names = array_map(fn (FileEntry $e) => $e->name, FileEntry::sort($entries));

    expect($names)->toBe(['assets', 'src', '.env', 'README.md']);
});

it('builds a listing command with a single stat call, escaped path and container name', function () {
    $cmd = fsService()->buildListCommand('/var/www/html');

    expect($cmd)
        ->toContain('docker exec')
        ->toContain('app-123')
        ->toContain('stat -c')
        ->toContain(escapeshellarg('/var/www/html'))
        // Must embed a REAL tab, not a literal backslash-t: stat -c does not
        // expand \t, so a literal escape would break the tab-delimited parse.
        ->toContain("%F\t%s")
        ->not->toContain('%F\\t%s');
});

it('rejects an unsafe listing path', function () {
    fsService()->buildListCommand('/tmp/$(reboot)');
})->throws(Exception::class);

it('parses a tab-delimited listing into sorted FileEntry rows', function () {
    $raw = implode("\n", [
        "file\t10\t1700000000\tREADME.md",
        "dir\t0\t1700000001\tsrc",
        "file\t5\t1700000002\t.env",
    ]);

    $entries = fsService()->parseListing($raw);

    expect($entries)->toHaveCount(3);
    expect($entries[0]->name)->toBe('src');
    expect($entries[0]->type)->toBe('dir');
    expect($entries[1]->name)->toBe('.env');
    expect($entries[2]->name)->toBe('README.md');
});

it('parses an empty listing to an empty array', function () {
    expect(fsService()->parseListing(null))->toBe([]);
    expect(fsService()->parseListing(''))->toBe([]);
});

it('lists a directory by running the built command over SSH', function () {
    Process::fake(['*' => Process::result(output: "dir\t0\t1\tsrc\nfile\t10\t2\tREADME.md")]);

    $server = fsServer();
    $entries = (new ContainerFilesystemService($server, 'app-123'))->list('/app');

    expect($entries)->toHaveCount(2);
    expect($entries[0]->name)->toBe('src');
});

it('falls back to / when the container has no WorkingDir', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $server = fsServer();
    expect((new ContainerFilesystemService($server, 'app-123'))->defaultRoot())->toBe('/');
});

it('refuses to read a file larger than the edit cap', function () {
    // The combined read command emits TOOBIG in a single round trip.
    Process::fake(['*' => Process::result(output: 'TOOBIG')]);

    $server = fsServer();
    (new ContainerFilesystemService($server, 'app-123'))->read('/app/big.bin');
})->throws(RuntimeException::class);

it('refuses to read a binary file', function () {
    Process::fake(['*' => Process::result(output: 'BINARY')]);

    $server = fsServer();
    (new ContainerFilesystemService($server, 'app-123'))->read('/app/a.bin');
})->throws(RuntimeException::class);

it('reads an editable text file in a single round trip', function () {
    // "OK" header line then the base64 payload, from one docker exec.
    Process::fake(['*' => Process::result(output: "OK\n".base64_encode("hello world\n"))]);

    $server = fsServer();
    $content = (new ContainerFilesystemService($server, 'app-123'))->read('/app/a.txt');

    expect($content)->toBe("hello world\n");
});

it('treats an empty file as editable text', function () {
    // Empty file => "OK" header with no payload.
    Process::fake(['*' => Process::result(output: 'OK')]);

    $server = fsServer();

    expect((new ContainerFilesystemService($server, 'app-123'))->read('/app/empty.txt'))->toBe('');
});

it('writes content larger than the shell argument limit via docker cp', function () {
    Process::fake(['*' => Process::result(output: '')]);
    $server = fsServer();

    (new ContainerFilesystemService($server, 'app-123'))->write('/app/big.txt', str_repeat('a', 200_000));

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker cp'));
});

it('base64-encodes content in the write command', function () {
    $cmd = fsService()->buildWriteCommand('/app/a.txt', "hi\n");

    expect($cmd)
        ->toContain('base64 -d')
        ->toContain(base64_encode("hi\n"))
        ->toContain(escapeshellarg('/app/a.txt'));
});

it('builds mkdir, rename and delete commands with escaped paths', function () {
    $svc = fsService();

    expect($svc->buildMkdirCommand('/app/new dir'))
        ->toContain('mkdir -p')
        ->toContain(escapeshellarg('/app/new dir'));
    expect($svc->buildRenameCommand('/app/a', '/app/b'))
        ->toContain('mv')
        ->toContain(escapeshellarg('/app/a'))
        ->toContain(escapeshellarg('/app/b'));
    expect($svc->buildDeleteCommand('/app/x'))
        ->toContain('rm -rf')
        ->toContain(escapeshellarg('/app/x'));
});

it('rejects unsafe paths in mutating builders', function () {
    fsService()->buildDeleteCommand('/app/$(reboot)');
})->throws(Exception::class);

it('parses the 7-field stat listing with owner and group, mapping the file type', function () {
    $raw = implode("\n", [
        "directory\t4096\t1700000000\t755\troot\troot\t.",
        "directory\t4096\t1700000000\t755\troot\troot\t..",
        "regular file\t497\t1700000001\t644\twww-data\twww-data\t50x.html",
        "directory\t4096\t1700000002\t755\tapp\tapp\tassets",
        "symbolic link\t11\t1700000003\t777\troot\troot\tlink",
    ]);

    $entries = fsService()->parseListing($raw);

    // The stat . and .. rows are dropped.
    expect($entries)->toHaveCount(3);
    expect($entries[0]->name)->toBe('assets');
    expect($entries[0]->type)->toBe('dir');
    expect($entries[0]->owner)->toBe('app');
    expect($entries[0]->group)->toBe('app');
    expect($entries[1]->name)->toBe('50x.html');
    expect($entries[1]->type)->toBe('file');
    expect($entries[1]->owner)->toBe('www-data');
    expect($entries[2]->name)->toBe('link');
    expect($entries[2]->type)->toBe('symlink');
});

it('parses permissions from the 5-field listing format', function () {
    $raw = "file\t497\t1700000000\t644\t50x.html\ndir\t0\t1700000001\t755\tassets";

    $entries = fsService()->parseListing($raw);

    expect($entries[0]->name)->toBe('assets');
    expect($entries[0]->perms)->toBe('755');
    expect($entries[1]->name)->toBe('50x.html');
    expect($entries[1]->perms)->toBe('644');
});

it('builds a create-file command that does not truncate an existing file', function () {
    $cmd = fsService()->buildCreateFileCommand('/app/new file.txt');

    expect($cmd)
        ->toContain('[ -e')
        ->toContain(': >')
        ->toContain(escapeshellarg('/app/new file.txt'));
});

it('builds a chmod command with a validated octal mode', function () {
    $cmd = fsService()->buildChmodCommand('/app/x.sh', '0755');

    expect($cmd)
        ->toContain('chmod 0755 --')
        ->toContain(escapeshellarg('/app/x.sh'));
});

it('rejects a non-octal chmod mode', function () {
    fsService()->buildChmodCommand('/app/x.sh', 'rwx');
})->throws(InvalidArgumentException::class);

it('rejects an unsafe path in the chmod builder', function () {
    fsService()->buildChmodCommand('/app/$(reboot)', '644');
})->throws(Exception::class);

it('uploads by scp-ing to the server then docker cp into the container', function () {
    Process::fake(['*' => Process::result(output: '')]);
    $server = fsServer();
    $local = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($local, 'data');

    (new ContainerFilesystemService($server, 'app-123'))->upload($local, '/app/plugins/x.jar');

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker cp')
        && str_contains($process->command, 'app-123'));
    @unlink($local);
});
