<?php

use App\Support\FtpsFilesystemAdapter;

it('formats FTPS URL hosts exactly once', function (string $host, string $expected) {
    $adapter = new FtpsFilesystemAdapter($host, 21, 'user', 'password');

    $url = (new ReflectionMethod($adapter, 'url'))->invoke($adapter, 'backup.sql', false);

    expect($url)->toBe('ftp://'.$expected.':21/backup.sql');
})->with([
    ['[2001:db8::1]', '[2001:db8::1]'],
    ['2001:db8::1', '[2001:db8::1]'],
    ['192.0.2.1', '192.0.2.1'],
    ['backup.example.com', 'backup.example.com'],
]);
