<?php

use App\Data\FileEntry;
use App\Models\LocalFileVolume;
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
        ->not->toContain('%F\\t%s')
        // find batches names, so a huge folder cannot overflow the argument limit like a glob.
        ->toContain('find . ! -name . -prune -exec stat -c')
        ->not->toContain('* .*');
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

it('strips the ./ prefix that find adds to listed names', function () {
    $raw = implode("\n", [
        "directory\t0\t1\t755\troot\troot\t./src",
        "regular file\t5\t2\t644\troot\troot\t./.env",
    ]);

    $names = array_map(fn ($entry) => $entry->name, fsService()->parseListing($raw));

    expect($names)->toBe(['src', '.env']);
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

it('writes content near or above the shell argument limit via docker cp', function (int $size) {
    Process::fake(['*' => Process::result(output: '')]);
    $server = fsServer();

    (new ContainerFilesystemService($server, 'app-123'))->write('/app/big.txt', str_repeat('a', $size));

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker cp'));
})->with([96 * 1024, 200_000]);

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

it('rejects failed or corrupt read responses', function (string $output) {
    Process::fake(['*' => Process::result(output: $output)]);
    (new ContainerFilesystemService(fsServer(), 'app-123'))->read('/app/a.txt');
})->with(['', 'ERROR', "OK\n!!!"])->throws(RuntimeException::class);

it('executes read guards for real file bytes through the non-root sudo parser', function (string $content, bool $editable) {
    $server = fsServer();
    $server->update(['user' => 'deploy']);
    $path = tempnam(sys_get_temp_dir(), 'fs-read-');
    file_put_contents($path, $content);
    Process::fake(function ($process) {
        $lines = explode("\n", $process->command);
        $body = implode("\n", array_slice($lines, 1, -1));
        $local = new Symfony\Component\Process\Process([
            'bash', '-c',
            'sudo() { "$@"; }; docker() { shift 2; "$@"; }; export -f sudo docker;'."\n".$body,
        ]);
        $local->run();

        return Process::result(output: $local->getOutput(), errorOutput: $local->getErrorOutput(), exitCode: $local->getExitCode());
    });
    try {
        $service = new ContainerFilesystemService($server, 'app-123');
        if ($editable) {
            expect($service->read($path))->toBe($content);
        } else {
            expect(fn () => $service->read($path))->toThrow(RuntimeException::class);
        }
    } finally {
        @unlink($path);
    }
})->with([
    'empty' => ['', true],
    'newline only' => ["\n\n", true],
    'text' => ["hello\n", true],
    'binary' => ["hello\0world", false],
]);

it('rejects a symlink to an oversized file before reading its content', function () {
    $server = fsServer();
    $path = tempnam(sys_get_temp_dir(), 'fs-size-');
    $link = $path.'-link';
    $handle = fopen($path, 'w');
    ftruncate($handle, LocalFileVolume::MAX_CONTENT_SIZE + 1);
    fclose($handle);
    symlink($path, $link);
    Process::fake(function ($process) {
        $body = implode("\n", array_slice(explode("\n", $process->command), 1, -1));
        $local = new Symfony\Component\Process\Process(['bash', '-c', 'sudo() { "$@"; }; docker() { shift 2; "$@"; }; export -f sudo docker;'."\n".$body]);
        $local->run();

        return Process::result(output: $local->getOutput(), exitCode: $local->getExitCode());
    });
    try {
        expect(fn () => (new ContainerFilesystemService($server, 'app-123'))->read($link))
            ->toThrow(RuntimeException::class, 'File is not editable');
    } finally {
        @unlink($link);
        @unlink($path);
    }
});

it('reads download type and size in one round trip', function (string $output, bool $isDir, int $size) {
    Process::fake(['*' => Process::result(output: $output)]);

    $info = (new ContainerFilesystemService(fsServer(), 'app-123'))->downloadInfo('/app/target');

    expect($info)->toBe(['isDir' => $isDir, 'size' => $size]);
    Process::assertRanTimes(fn () => true, 1);
})->with([
    'file' => ['file 497', false, 497],
    'folder in KiB' => ['dir 4', true, 4096],
]);

it('rejects a download target that does not exist', function () {
    Process::fake(['*' => Process::result(output: '')]);

    (new ContainerFilesystemService(fsServer(), 'app-123'))->downloadInfo('/app/missing');
})->throws(RuntimeException::class, 'File not found.');

it('builds download commands that write to stdout instead of a temp file', function () {
    $svc = fsService();

    expect($svc->buildDownloadCommand("/app/it's.txt", false))
        ->toBe("docker exec 'app-123' cat -- ".escapeshellarg("/app/it's.txt"));
    expect($svc->buildDownloadCommand('/app/dir', true))
        ->toBe("docker exec 'app-123' tar czf - -C '/app/dir' .");
});

it('streams the remote command output to the writer in chunks', function () {
    $server = fsServer();
    $svc = new class($server, 'app-123') extends ContainerFilesystemService
    {
        public ?string $remoteCommand = null;

        protected function sshCommand(string $command): string
        {
            $this->remoteCommand = $command;

            return 'head -c 200000 /dev/zero';
        }
    };
    $received = '';
    $chunks = 0;

    $svc->streamDownload('/app/a.bin', false, function (string $chunk) use (&$received, &$chunks) {
        $received .= $chunk;
        $chunks++;
    });

    expect($svc->remoteCommand)->toEndWith("docker exec 'app-123' cat -- '/app/a.bin'");
    expect(strlen($received))->toBe(200000);
    expect($chunks)->toBeGreaterThan(1);
});
