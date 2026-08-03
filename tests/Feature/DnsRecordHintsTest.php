<?php

use App\Support\DnsRecordHints;

it('builds a and aaaa records for a single hostname', function () {
    $records = DnsRecordHints::forTarget('app.example.com', '203.0.113.10', '2001:db8::1');

    expect($records)->toHaveCount(2)
        ->and($records[0])->toMatchArray([
            'type' => 'A',
            'name' => 'app.example.com',
            'value' => '203.0.113.10',
        ])
        ->and($records[1])->toMatchArray([
            'type' => 'AAAA',
            'name' => 'app.example.com',
            'value' => '2001:db8::1',
        ]);
});

it('builds entries for every hostname without duplicates', function () {
    $records = DnsRecordHints::forHostnames([
        'app.example.com',
        'www.example.com',
        'app.example.com',
        'https://api.example.com/path',
    ], '203.0.113.10');

    expect($records)->toHaveCount(3)
        ->and(collect($records)->pluck('name')->all())->toBe([
            'api.example.com',
            'app.example.com',
            'www.example.com',
        ])
        ->and(collect($records)->pluck('type')->unique()->all())->toBe(['A']);
});

it('formats a copy-paste text block with all entries', function () {
    $text = DnsRecordHints::toCopyText([
        ['type' => 'A', 'name' => 'app.example.com', 'value' => '203.0.113.10'],
        ['type' => 'A', 'name' => 'www.example.com', 'value' => '203.0.113.10'],
    ]);

    expect($text)->toBe("Type\tName\tValue\nA\tapp.example.com\t203.0.113.10\nA\twww.example.com\t203.0.113.10");
});
