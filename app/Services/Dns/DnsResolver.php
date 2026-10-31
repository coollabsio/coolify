<?php

namespace App\Services\Dns;

/**
 * Minimal DNS client for A/AAAA lookups against one specific resolver.
 *
 * It parses only the address RDATA, which is a fixed 4 or 16 bytes, and never
 * decompresses names. Domain names in the question and answer sections are
 * skipped with a bounded, non-recursive walk that treats a compression pointer
 * as a two-byte field without following it. A malicious response therefore
 * cannot trigger pointer recursion or a decompression loop.
 *
 * The public surface (query/hasError/getLasterror and the TYPE_* constants)
 * mirrors the subset of the previous phpdns client that the application used.
 */
class DnsResolver
{
    public const TYPE_A = 'A';

    public const TYPE_AAAA = 'AAAA';

    /** @var array<string, int> */
    private const TYPE_IDS = [self::TYPE_A => 1, self::TYPE_AAAA => 28];

    private bool $error = false;

    private string $lasterror = '';

    public function __construct(
        private readonly string $server,
        private readonly int $port = 53,
        private readonly int $timeout = 5,
        private readonly bool $udp = true,
    ) {}

    public function hasError(): bool
    {
        return $this->error;
    }

    public function getLasterror(): string
    {
        return $this->lasterror;
    }

    /**
     * Query the resolver for the address records of one host.
     *
     * @return array<int, DnsRecord>|false
     */
    public function query(string $host, string $type = self::TYPE_A): array|false
    {
        $this->error = false;
        $this->lasterror = '';

        $qtype = self::TYPE_IDS[$type] ?? self::TYPE_IDS[self::TYPE_A];
        $id = random_int(0, 0xFFFF);
        $request = self::buildQuery($host, $qtype, $id);

        $raw = $this->udp ? $this->exchangeUdp($request) : $this->exchangeTcp($request);
        if ($raw === false) {
            return false;
        }

        if (strlen($raw) < 12) {
            return $this->fail('Return buffer too small');
        }

        $header = unpack('nid/nflags', substr($raw, 0, 12));
        if ($header['id'] !== $id) {
            return $this->fail('DNS response transaction id mismatch');
        }

        // TC (truncation) bit set: ask the caller to retry over TCP.
        if ($this->udp && ($header['flags'] & 0x0200) !== 0) {
            return $this->fail('Response too big for UDP, retry with TCP');
        }

        return self::parseAnswers($raw, $type);
    }

    /**
     * Parse the answer section, returning only the requested address records.
     *
     * Pure function of the raw response bytes, so it is safe to call directly
     * in tests with crafted (including malicious) payloads.
     *
     * @return array<int, DnsRecord>
     */
    public static function parseAnswers(string $raw, string $type): array
    {
        $length = strlen($raw);
        if ($length < 12) {
            return [];
        }

        $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($raw, 0, 12));
        $offset = 12;

        for ($i = 0; $i < $header['qd']; $i++) {
            $offset = self::skipName($raw, $offset) + 4; // skip QTYPE + QCLASS
            if ($offset > $length) {
                return [];
            }
        }

        $wantId = self::TYPE_IDS[$type] ?? self::TYPE_IDS[self::TYPE_A];
        $records = [];

        for ($i = 0; $i < $header['an']; $i++) {
            $offset = self::skipName($raw, $offset);
            if ($offset + 10 > $length) {
                break;
            }

            $rr = unpack('ntype/nclass/Nttl/nrdlength', substr($raw, $offset, 10));
            $offset += 10;
            $rdlength = $rr['rdlength'];

            if ($offset + $rdlength > $length) {
                break;
            }

            $rdata = substr($raw, $offset, $rdlength);
            $offset += $rdlength;

            if ($rr['type'] !== $wantId) {
                continue;
            }

            if (($wantId === 1 && $rdlength === 4) || ($wantId === 28 && $rdlength === 16)) {
                $ip = @inet_ntop($rdata);
                if ($ip !== false) {
                    $records[] = new DnsRecord($type, $ip);
                }
            }
        }

        return $records;
    }

    /**
     * Skip one domain name and return the offset just past its on-wire field.
     *
     * Compression pointers are not followed: the name value is never needed, so
     * a pointer is treated as a fixed two-byte field. The uncompressed label
     * walk is capped, so the method always terminates in bounded time and a
     * self-referential or looping pointer cannot cause recursion.
     */
    private static function skipName(string $raw, int $offset): int
    {
        $length = strlen($raw);

        for ($labels = 0; $labels <= 128; $labels++) {
            if ($offset >= $length) {
                return $length;
            }

            $marker = ord($raw[$offset]);

            if ($marker === 0) {
                return $offset + 1;
            }

            if (($marker & 0xC0) === 0xC0) {
                return $offset + 2;
            }

            if (($marker & 0xC0) !== 0) {
                return $length; // reserved bits set: malformed
            }

            $offset += 1 + $marker;
        }

        return $length; // too many labels: malformed
    }

    private static function buildQuery(string $host, int $qtype, int $id): string
    {
        $query = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0); // RD set, one question

        foreach (explode('.', trim($host, '.')) as $label) {
            if ($label === '') {
                continue;
            }
            $label = substr($label, 0, 63);
            $query .= chr(strlen($label)).$label;
        }

        $query .= "\0";
        $query .= pack('nn', $qtype, 1); // QTYPE, QCLASS IN

        return $query;
    }

    private function exchangeUdp(string $request): string|false
    {
        $socket = @fsockopen('udp://'.$this->server, $this->port, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            return $this->fail("Failed to open socket (code {$errno}: {$errstr})");
        }

        stream_set_timeout($socket, $this->timeout);

        if (@fwrite($socket, $request) === false) {
            fclose($socket);

            return $this->fail('Failed to write question to socket');
        }

        $raw = @fread($socket, 4096);
        fclose($socket);

        if ($raw === false || $raw === '') {
            return $this->fail('Failed to read data buffer');
        }

        return $raw;
    }

    private function exchangeTcp(string $request): string|false
    {
        $socket = @fsockopen('tcp://'.$this->server, $this->port, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            return $this->fail("Failed to open socket (code {$errno}: {$errstr})");
        }

        stream_set_timeout($socket, $this->timeout);

        if (@fwrite($socket, pack('n', strlen($request)).$request) === false) {
            fclose($socket);

            return $this->fail('Failed to write question to TCP socket');
        }

        $sizeData = $this->readTcp($socket, 2);
        if ($sizeData === false) {
            fclose($socket);

            return $this->fail('Failed to read size from TCP socket');
        }

        $size = unpack('n', $sizeData)[1];
        $raw = $size > 0 ? $this->readTcp($socket, $size) : '';
        fclose($socket);

        if ($raw === false) {
            return $this->fail('Failed to read data buffer');
        }

        return $raw;
    }

    /**
     * @param  resource  $socket
     */
    private function readTcp($socket, int $size): string|false
    {
        $buffer = '';
        while (strlen($buffer) < $size) {
            $chunk = @fread($socket, $size - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }

        return strlen($buffer) === $size ? $buffer : false;
    }

    private function fail(string $message): false
    {
        $this->error = true;
        $this->lasterror = $message;

        return false;
    }
}
