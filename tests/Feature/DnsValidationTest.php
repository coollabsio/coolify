<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PurplePixie\PhpDns\DNSQuery;
use PurplePixie\PhpDns\DNSResult;
use PurplePixie\PhpDns\DNSTypes;

uses(RefreshDatabase::class);

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
})->with([
    'target server IP' => '203.0.113.10',
    'Cloudflare IP' => '104.16.0.1',
]);
