<?php

use App\Actions\Shared\CheckDomainDns;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PurplePixie\PhpDns\DNSQuery;
use PurplePixie\PhpDns\DNSResult;
use PurplePixie\PhpDns\DNSTypes;

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

    app()->bind(DNSQuery::class, function ($app, array $parameters) use ($queriedServers, $resolvedIp) {
        return new class($parameters['server'], $queriedServers, $resolvedIp) extends DNSQuery
        {
            public function __construct(
                private readonly string $dnsServer,
                private readonly ArrayObject $queriedServers,
                private readonly string $resolvedIp,
            ) {
                parent::__construct($dnsServer);
            }

            public function query(string $question, string $typeName = DNSTypes::NAME_A)
            {
                $this->queriedServers->append($this->dnsServer);

                return [new DNSResult($typeName, 1, 'IN', 60, $this->resolvedIp, $question, '', [])];
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
    app()->bind(DNSQuery::class, function () use ($queryCount) {
        $queryCount->append(true);

        return new DNSQuery('192.0.2.1');
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
