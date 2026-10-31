<?php

use App\Services\Dns\DnsResolver;

it('limits direct DNS queries to five seconds', function () {
    $query = createDnsQuery('1.1.1.1');
    $timeout = (new ReflectionClass(DnsResolver::class))->getProperty('timeout')->getValue($query);

    expect($timeout)->toBe(5);
});
