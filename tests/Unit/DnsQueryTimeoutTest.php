<?php

use PurplePixie\PhpDns\DNSQuery;

it('limits direct DNS queries to five seconds', function () {
    $query = createDnsQuery('1.1.1.1');
    $timeout = (new ReflectionClass(DNSQuery::class))->getProperty('timeout')->getValue($query);

    expect($timeout)->toBe(5);
});
