<?php

use App\Livewire\Project\Shared\FileBrowser;

test('validatePath accepts valid absolute paths', function () {
    $component = new FileBrowser;
    $method = new ReflectionMethod($component, 'validatePath');

    expect($method->invoke($component, '/'))->toBeTrue();
    expect($method->invoke($component, '/home'))->toBeTrue();
    expect($method->invoke($component, '/var/log/app'))->toBeTrue();
    expect($method->invoke($component, '/usr/local/bin'))->toBeTrue();
    expect($method->invoke($component, '/tmp/my-file.txt'))->toBeTrue();
    expect($method->invoke($component, '/path/with spaces'))->toBeTrue();
});

test('validatePath rejects relative paths', function () {
    $component = new FileBrowser;
    $method = new ReflectionMethod($component, 'validatePath');

    expect($method->invoke($component, 'relative/path'))->toBeFalse();
    expect($method->invoke($component, './current'))->toBeFalse();
    expect($method->invoke($component, 'file.txt'))->toBeFalse();
});

test('validatePath rejects directory traversal', function () {
    $component = new FileBrowser;
    $method = new ReflectionMethod($component, 'validatePath');

    expect($method->invoke($component, '/path/../etc/passwd'))->toBeFalse();
    expect($method->invoke($component, '/path/..hidden'))->toBeFalse();
    expect($method->invoke($component, '/..'))->toBeFalse();
    expect($method->invoke($component, '/../../etc'))->toBeFalse();
});

test('validatePath rejects shell metacharacters', function () {
    $component = new FileBrowser;
    $method = new ReflectionMethod($component, 'validatePath');

    expect($method->invoke($component, '/path/$(whoami)'))->toBeFalse();
    expect($method->invoke($component, '/path/`id`'))->toBeFalse();
    expect($method->invoke($component, '/path/;rm -rf /'))->toBeFalse();
    expect($method->invoke($component, '/path/|cat /etc/passwd'))->toBeFalse();
    expect($method->invoke($component, '/path/&bg'))->toBeFalse();
    expect($method->invoke($component, '/path/>output'))->toBeFalse();
    expect($method->invoke($component, '/path/<input'))->toBeFalse();
    expect($method->invoke($component, '/path/!history'))->toBeFalse();
});

test('validatePath rejects null bytes', function () {
    $component = new FileBrowser;
    $method = new ReflectionMethod($component, 'validatePath');

    expect($method->invoke($component, "/path/\0hidden"))->toBeFalse();
});

test('parseLsOutput parses standard ls output', function () {
    $component = new FileBrowser;
    $method = new ReflectionMethod($component, 'parseLsOutput');

    $output = <<<'LS'
total 48
drwxr-xr-x   2 root root 4096 Mar  2 12:00 config
-rw-r--r--   1 www  www  1234 Mar  1 09:30 index.html
-rwxr-xr-x   1 root root  567 Feb 28 15:45 start.sh
LS;

    $entries = $method->invoke($component, $output);

    expect($entries)->toHaveCount(3);

    expect($entries[0]['name'])->toBe('config');
    expect($entries[0]['isDirectory'])->toBeTrue();
    expect($entries[0]['permissions'])->toBe('drwxr-xr-x');
    expect($entries[0]['owner'])->toBe('root');

    expect($entries[1]['name'])->toBe('index.html');
    expect($entries[1]['isDirectory'])->toBeFalse();
    expect($entries[1]['size'])->toBe(1234);
    expect($entries[1]['owner'])->toBe('www');

    expect($entries[2]['name'])->toBe('start.sh');
    expect($entries[2]['permissions'])->toBe('-rwxr-xr-x');
});

test('parseLsOutput sorts directories first then alphabetically', function () {
    $component = new FileBrowser;
    $method = new ReflectionMethod($component, 'parseLsOutput');

    $output = <<<'LS'
total 16
-rw-r--r--   1 root root  100 Mar  1 10:00 zebra.txt
drwxr-xr-x   2 root root 4096 Mar  1 10:00 alpha
-rw-r--r--   1 root root  200 Mar  1 10:00 apple.txt
drwxr-xr-x   2 root root 4096 Mar  1 10:00 beta
LS;

    $entries = $method->invoke($component, $output);

    expect($entries)->toHaveCount(4);
    expect($entries[0]['name'])->toBe('alpha');
    expect($entries[0]['isDirectory'])->toBeTrue();
    expect($entries[1]['name'])->toBe('beta');
    expect($entries[1]['isDirectory'])->toBeTrue();
    expect($entries[2]['name'])->toBe('apple.txt');
    expect($entries[2]['isDirectory'])->toBeFalse();
    expect($entries[3]['name'])->toBe('zebra.txt');
    expect($entries[3]['isDirectory'])->toBeFalse();
});

test('parseLsOutput skips . and .. entries', function () {
    $component = new FileBrowser;
    $method = new ReflectionMethod($component, 'parseLsOutput');

    $output = <<<'LS'
total 8
drwxr-xr-x   3 root root 4096 Mar  1 10:00 .
drwxr-xr-x   5 root root 4096 Mar  1 10:00 ..
-rw-r--r--   1 root root  100 Mar  1 10:00 file.txt
LS;

    $entries = $method->invoke($component, $output);

    expect($entries)->toHaveCount(1);
    expect($entries[0]['name'])->toBe('file.txt');
});

test('parseLsOutput handles symlinks', function () {
    $component = new FileBrowser;
    $method = new ReflectionMethod($component, 'parseLsOutput');

    $output = <<<'LS'
total 4
lrwxrwxrwx   1 root root   11 Mar  1 10:00 link -> /etc/target
-rw-r--r--   1 root root  100 Mar  1 10:00 normal.txt
LS;

    $entries = $method->invoke($component, $output);

    expect($entries)->toHaveCount(2);
    expect($entries[0]['name'])->toBe('normal.txt');

    $symlink = collect($entries)->firstWhere('name', 'link');
    expect($symlink['isSymlink'])->toBeTrue();
    expect($symlink['linkTarget'])->toBe('/etc/target');
});

test('parseLsOutput handles empty output', function () {
    $component = new FileBrowser;
    $method = new ReflectionMethod($component, 'parseLsOutput');

    expect($method->invoke($component, ''))->toBe([]);
    expect($method->invoke($component, null))->toBe([]);
    expect($method->invoke($component, 'total 0'))->toBe([]);
});

test('parseLsOutput handles files with spaces in names', function () {
    $component = new FileBrowser;
    $method = new ReflectionMethod($component, 'parseLsOutput');

    $output = <<<'LS'
total 4
-rw-r--r--   1 root root  100 Mar  1 10:00 my file name.txt
drwxr-xr-x   2 root root 4096 Mar  1 10:00 my folder
LS;

    $entries = $method->invoke($component, $output);

    expect($entries)->toHaveCount(2);
    expect(collect($entries)->firstWhere('isDirectory', true)['name'])->toBe('my folder');
    expect(collect($entries)->firstWhere('isDirectory', false)['name'])->toBe('my file name.txt');
});
