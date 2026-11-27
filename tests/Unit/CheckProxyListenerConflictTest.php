<?php

use App\Actions\Proxy\CheckProxy;
use Symfony\Component\Process\Process;

/**
 * Runs the real port check script on this machine, with fake `docker` and `ss` commands that print $listeners.
 *
 * @param  list<string>|null  $hostAddresses
 */
function runPortCheckScript(?array $hostAddresses, string $listeners): string
{
    $script = (new ReflectionMethod(CheckProxy::class, 'buildPortCheckScript'))
        ->invoke(new CheckProxy, 443, 'coolify-proxy', $hostAddresses);

    $bin = sys_get_temp_dir().'/check-proxy-'.bin2hex(random_bytes(4));
    mkdir($bin);
    file_put_contents("$bin/docker", "#!/bin/sh\nexit 0\n");
    file_put_contents("$bin/ss", "#!/bin/sh\nprintf '%s' \"\$FAKE_LISTENERS\"\n");
    chmod("$bin/docker", 0755);
    chmod("$bin/ss", 0755);

    try {
        $process = new Process(['sh'], env: ['PATH' => "$bin:".getenv('PATH'), 'FAKE_LISTENERS' => $listeners], input: $script);
        $process->mustRun();

        return trim($process->getOutput());
    } finally {
        array_map(unlink(...), glob("$bin/*"));
        rmdir($bin);
    }
}

it('reports only the listeners that block the proxy bind', function (?array $hostAddresses, string $listener, bool $conflict) {
    $output = runPortCheckScript($hostAddresses, "tcp   0      4096   $listener   0.0.0.0:*\n");

    expect(str_starts_with($output, 'port_conflict|'))->toBe($conflict)
        ->and($conflict || $output === 'port_free')->toBeTrue();
})->with([
    // Docker cannot bind 0.0.0.0:443 while any process listens on port 443.
    'all addresses vs specific listener' => [null, '100.64.0.1:443', true],
    'all addresses vs wildcard listener' => [null, '0.0.0.0:443', true],
    'specific IP vs other specific listener' => [['203.0.113.5'], '100.64.0.1:443', false],
    'specific IP vs same IP' => [['203.0.113.5'], '203.0.113.5:443', true],
    'specific IP vs IPv4 wildcard' => [['203.0.113.5'], '0.0.0.0:443', true],
    'specific IP vs dual-stack wildcard' => [['203.0.113.5'], '*:443', true],
    'specific IP vs interface-bound wildcard' => [['203.0.113.5'], '0.0.0.0%eth0:443', true],
    'specific IP vs other port' => [['203.0.113.5'], '0.0.0.0:4430', false],
    'IPv6 vs same IPv6' => [['2001:db8::1'], '[2001:db8::1]:443', true],
    'IPv6 vs IPv6 wildcard' => [['2001:db8::1'], '[::]:443', true],
    'IPv6 vs other IPv6' => [['2001:db8::1'], '[2001:db8::2]:443', false],
]);

it('matches netstat and lsof listeners the same way', function (string $listener, bool $conflict) {
    $pattern = (new ReflectionMethod(CheckProxy::class, 'blockingListenerPattern'))
        ->invoke(new CheckProxy, 443, ['203.0.113.5', '2001:db8::1']);

    expect(preg_match('~'.$pattern.'~', $listener) === 1)->toBe($conflict);
})->with([
    'netstat IPv6 wildcard' => ['tcp6       0      0 :::443                  :::*                    LISTEN', true],
    'netstat same IPv6' => ['tcp6       0      0 2001:db8::1:443         :::*                    LISTEN', true],
    'netstat other IPv6' => ['tcp6       0      0 2001:db8::2:443         :::*                    LISTEN', false],
    'netstat other IPv4' => ['tcp        0      0 100.64.0.1:443          0.0.0.0:*               LISTEN', false],
    'lsof wildcard' => ['nginx 1 root 6u IPv4 1 0t0 TCP *:443 (LISTEN)', true],
    'lsof other IPv4' => ['nginx 1 root 6u IPv4 1 0t0 TCP 100.64.0.1:443 (LISTEN)', false],
]);
