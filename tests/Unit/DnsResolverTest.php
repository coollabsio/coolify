<?php

use App\Services\Dns\DnsRecord;
use App\Services\Dns\DnsResolver;

/**
 * Build a raw DNS response: 12-byte header plus one answer record whose NAME
 * field is the given bytes, followed by TYPE/CLASS/TTL/RDLENGTH/RDATA.
 */
function dnsResponse(string $name, int $type, string $rdata, int $ancount = 1): string
{
    $header = pack('nnnnnn', 0x1234, 0x8180, 0, $ancount, 0, 0); // qdcount 0, ancount 1

    return $header.$name.pack('nnNn', $type, 1, 300, strlen($rdata)).$rdata;
}

it('parses an A record', function () {
    $raw = dnsResponse("\x03www\x07example\x03com\x00", 1, inet_pton('93.184.216.34'));

    $records = DnsResolver::parseAnswers($raw, DnsResolver::TYPE_A);

    expect($records)->toHaveCount(1)
        ->and($records[0])->toBeInstanceOf(DnsRecord::class)
        ->and($records[0]->getType())->toBe('A')
        ->and($records[0]->getData())->toBe('93.184.216.34');
});

it('parses an AAAA record', function () {
    $raw = dnsResponse("\x03www\x07example\x03com\x00", 28, inet_pton('2606:4700::1'));

    $records = DnsResolver::parseAnswers($raw, DnsResolver::TYPE_AAAA);

    expect($records)->toHaveCount(1)
        ->and($records[0]->getData())->toBe('2606:4700::1');
});

it('ignores records of other types', function () {
    $raw = dnsResponse("\x03www\x00", 5, "\x03abc\x00"); // CNAME

    expect(DnsResolver::parseAnswers($raw, DnsResolver::TYPE_A))->toBe([]);
});

// Regression for the phpdns pointer-recursion crash: a self-referential
// compression pointer in the answer NAME must be skipped as a two-byte field,
// never followed. Before this resolver, such a response crashed the PHP
// process with unbounded recursion; here it must return in bounded time.
it('does not recurse on a self-referential compression pointer', function () {
    // Answer starts at offset 12. NAME is a pointer 0xC0 0x0C -> offset 12 (itself).
    $raw = dnsResponse("\xC0\x0C", 1, inet_pton('203.0.113.7'));

    $records = DnsResolver::parseAnswers($raw, DnsResolver::TYPE_A);

    expect($records)->toHaveCount(1)
        ->and($records[0]->getData())->toBe('203.0.113.7');
});

it('does not loop on mutually-referential compression pointers', function () {
    // Two answers whose names point at each other; parsing must still terminate.
    $header = pack('nnnnnn', 0x1234, 0x8180, 0, 2, 0, 0);
    $answerA = "\xC0\x20".pack('nnNn', 1, 1, 300, 4).inet_pton('198.51.100.1'); // name -> offset 32
    $answerB = "\xC0\x0C".pack('nnNn', 1, 1, 300, 4).inet_pton('198.51.100.2'); // name -> offset 12
    $raw = $header.$answerA.$answerB;

    $records = DnsResolver::parseAnswers($raw, DnsResolver::TYPE_A);

    expect(array_map(fn (DnsRecord $r) => $r->getData(), $records))
        ->toBe(['198.51.100.1', '198.51.100.2']);
});

it('returns no records for a truncated or empty buffer', function () {
    expect(DnsResolver::parseAnswers('', DnsResolver::TYPE_A))->toBe([])
        ->and(DnsResolver::parseAnswers("\x00\x00\x00", DnsResolver::TYPE_A))->toBe([]);
});

it('stops safely when the answer count exceeds the available bytes', function () {
    // Claims 50 answers but only provides one; must not read past the buffer.
    $raw = dnsResponse("\x03www\x00", 1, inet_pton('192.0.2.9'), ancount: 50);

    $records = DnsResolver::parseAnswers($raw, DnsResolver::TYPE_A);

    expect($records)->toHaveCount(1)
        ->and($records[0]->getData())->toBe('192.0.2.9');
});
