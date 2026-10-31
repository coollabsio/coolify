<?php

use App\Actions\Shared\CheckDomainDns;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\Dns\DnsRecord;
use App\Services\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a skipped dns result when instance validation is disabled', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_dns_validation_enabled' => false]
    ));

    $result = CheckDomainDns::run(
        ['https://example.com' => 'https://example.com'],
        new Server(['ip' => '203.0.113.10']),
        '203.0.113.10',
    );

    expect($result['https://example.com'])
        ->toMatchArray([
            'status' => 'skipped',
            'message' => 'DNS validation is disabled in instance settings.',
            'expected_ip' => '203.0.113.10',
        ])
        ->and($result['https://example.com']['checked_at'])->not->toBeNull();
});

it('stops querying DNS servers after finding a matching IP', function (string $resolvedIp) {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        [
            'is_dns_validation_enabled' => true,
            'custom_dns_servers' => '192.0.2.1,192.0.2.2',
        ]
    ));

    $queriedServers = new ArrayObject;
    $targetIp = '203.0.113.10';

    app()->bind(DnsResolver::class, function ($app, array $parameters) use ($queriedServers, $resolvedIp) {
        return new class($parameters['server'], $queriedServers, $resolvedIp) extends DnsResolver
        {
            public function __construct(
                private readonly string $dnsServer,
                private readonly ArrayObject $queriedServers,
                private readonly string $resolvedIp,
            ) {
                parent::__construct($dnsServer);
            }

            public function query(string $question, string $typeName = DnsResolver::TYPE_A): array|false
            {
                $this->queriedServers->append($this->dnsServer);

                return [new DnsRecord($typeName, $this->resolvedIp)];
            }

            public function hasError(): bool
            {
                return false;
            }
        };
    });

    $server = new Server(['ip' => $targetIp]);
    $server->id = 1;

    expect(validateDNSEntry('https://example.com', $server))->toBeTrue()
        ->and($queriedServers->getArrayCopy())->toBe(['192.0.2.1']);

    $result = CheckDomainDns::run(['example' => 'https://example.com'], $server, $targetIp);

    expect($result['example']['status'])->toBe('ok')
        ->and($queriedServers->getArrayCopy())->toBe(['192.0.2.1', '192.0.2.1']);
})->with([
    'target server IP' => '203.0.113.10',
    'Cloudflare IP' => '104.16.0.1',
]);

it('does not start another resolver query after the total dns budget is exhausted', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        [
            'is_dns_validation_enabled' => true,
            'custom_dns_servers' => '192.0.2.1,192.0.2.2',
        ]
    ));

    $queryCount = new ArrayObject;
    app()->bind(DnsResolver::class, function () use ($queryCount) {
        $queryCount->append(true);

        return new DnsResolver('192.0.2.1');
    });

    $result = CheckDomainDns::run(
        ['example' => 'https://example.com'],
        new Server(['ip' => '203.0.113.10']),
        '203.0.113.10',
        timeoutSeconds: 0,
    );

    expect($result['example']['status'])->toBe('failed')
        ->and($result['example']['message'])->toBe('Could not validate DNS for this domain.')
        ->and($queryCount)->toHaveCount(0);
});

it('falls back to the system resolver when custom dns servers return no answers', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        [
            'is_dns_validation_enabled' => true,
            'custom_dns_servers' => '192.0.2.1',
        ]
    ));

    app()->bind(DnsResolver::class, fn () => new class('192.0.2.1') extends DnsResolver
    {
        public function query(string $question, string $typeName = DnsResolver::TYPE_A): false
        {
            return false;
        }

        public function hasError(): bool
        {
            return true;
        }
    });

    $action = new class extends CheckDomainDns
    {
        protected function resolveWithSystemDns(string $host, string $type): array
        {
            return ['203.0.113.10'];
        }
    };

    $result = $action->handle(
        ['example' => 'https://example.com'],
        new Server(['ip' => '203.0.113.10']),
        '203.0.113.10',
    );

    expect($result['example']['status'])->toBe('ok');
});

it('does not use the system resolver after a custom dns server returns an address', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        [
            'is_dns_validation_enabled' => true,
            'custom_dns_servers' => '192.0.2.1',
        ]
    ));

    app()->bind(DnsResolver::class, fn () => new class('192.0.2.1') extends DnsResolver
    {
        public function query(string $question, string $typeName = DnsResolver::TYPE_A): array
        {
            return [new DnsRecord($typeName, '198.51.100.20')];
        }

        public function hasError(): bool
        {
            return false;
        }
    });

    $action = new class extends CheckDomainDns
    {
        protected function resolveWithSystemDns(string $host, string $type): array
        {
            throw new RuntimeException('The system resolver must not be used.');
        }
    };

    $result = $action->handle(
        ['example' => 'https://example.com'],
        new Server(['ip' => '203.0.113.10']),
        '203.0.113.10',
    );

    expect($result['example']['status'])->toBe('failed');
});

it('treats equivalent IPv6 notations as the same address', function (string $resolvedIp, string $expectedIp) {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_dns_validation_enabled' => true, 'custom_dns_servers' => '192.0.2.1'],
    ));

    app()->bind(DnsResolver::class, fn () => new class('192.0.2.1', $resolvedIp) extends DnsResolver
    {
        public function __construct(string $server, private readonly string $resolvedIp)
        {
            parent::__construct($server);
        }

        public function query(string $question, string $typeName = DnsResolver::TYPE_A): array|false
        {
            return [new DnsRecord($typeName, $this->resolvedIp)];
        }

        public function hasError(): bool
        {
            return false;
        }
    });

    $server = new Server(['ip' => $expectedIp]);
    $server->id = 1;

    $result = CheckDomainDns::run(['example' => 'https://example.com'], $server, $expectedIp);

    expect($result['example']['status'])->toBe('ok');
})->with([
    'compressed record, expanded expectation' => ['2001:db8::1', '2001:0db8:0:0::1'],
    'expanded record, compressed expectation' => ['2001:0DB8:0000:0000:0000:0000:0000:0001', '2001:db8::1'],
]);

it('treats equivalent IPv6 notations from the system resolver as the same address', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_dns_validation_enabled' => true, 'custom_dns_servers' => ''],
    ));

    $action = new class extends CheckDomainDns
    {
        protected function resolveWithSystemDns(string $host, string $type): array
        {
            return ['2001:db8::1'];
        }
    };
    $server = new Server(['ip' => '2001:0db8:0:0::1']);
    $server->id = 1;

    expect($action->handle(['example' => 'https://example.com'], $server, '2001:0db8:0:0::1')['example']['status'])->toBe('ok');
});

it('validateDNSEntry treats equivalent IPv6 notations as the same address', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_dns_validation_enabled' => true, 'custom_dns_servers' => '192.0.2.1'],
    ));

    app()->bind(DnsResolver::class, fn () => new class('192.0.2.1') extends DnsResolver
    {
        public function query(string $question, string $typeName = DnsResolver::TYPE_A): array|false
        {
            return [new DnsRecord($typeName, '2001:db8::1')];
        }

        public function hasError(): bool
        {
            return false;
        }
    });

    $server = new Server(['ip' => '2001:0db8:0:0::1']);
    $server->id = 1;

    expect(validateDNSEntry('https://example.com', $server))->toBeTrue();
});

it('passes bracketed IPv6 custom dns servers to the dns query', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        [
            'is_dns_validation_enabled' => true,
            'custom_dns_servers' => '2606:4700:4700::1111,[2001:db8::53],192.0.2.1',
        ]
    ));

    $queriedServers = new ArrayObject;
    app()->bind(DnsResolver::class, function ($app, array $parameters) use ($queriedServers) {
        $queriedServers->append($parameters['server']);

        return new class('192.0.2.1') extends DnsResolver
        {
            public function query(string $question, string $typeName = DnsResolver::TYPE_A): false
            {
                return false;
            }

            public function hasError(): bool
            {
                return true;
            }
        };
    });

    $action = new class extends CheckDomainDns
    {
        protected function resolveWithSystemDns(string $host, string $type): array
        {
            return [];
        }
    };

    $action->handle(['example' => 'https://example.com'], new Server(['ip' => '203.0.113.10']), '203.0.113.10');

    expect($queriedServers->getArrayCopy())->toBe(['[2606:4700:4700::1111]', '[2001:db8::53]', '192.0.2.1']);
});
