<?php

namespace App\Rules;

use App\Models\InstanceSettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use InvalidArgumentException;

class PrivateIpv4Cidr implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            self::range((string) $value);
        } catch (InvalidArgumentException $exception) {
            $fail($exception->getMessage());
        }
    }

    /** @return array{start: int, end: int, prefix: int, canonical: string} */
    public static function range(string $cidr): array
    {
        if (! preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d|[12]\d|3[0-2])$/', $cidr, $matches)
            || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('The cluster CIDR must be a valid private IPv4 CIDR.');
        }

        $prefix = (int) $matches[2];
        if ($prefix > 25) {
            throw new InvalidArgumentException('The cluster CIDR must contain enough addresses for 100 Nodes.');
        }

        $ip = (int) sprintf('%u', ip2long($matches[1]));
        $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
        $start = $ip & $mask;
        $end = $start + (2 ** (32 - $prefix)) - 1;
        if ($ip !== $start) {
            throw new InvalidArgumentException('The cluster CIDR must use the network address.');
        }

        $privateRanges = [
            [self::ip('10.0.0.0'), self::ip('10.255.255.255')],
            [self::ip('172.16.0.0'), self::ip('172.31.255.255')],
            [self::ip('192.168.0.0'), self::ip('192.168.255.255')],
        ];
        if (! collect($privateRanges)->contains(fn (array $range): bool => $start >= $range[0] && $end <= $range[1])) {
            throw new InvalidArgumentException('The cluster CIDR must be a private IPv4 range.');
        }

        return ['start' => $start, 'end' => $end, 'prefix' => $prefix, 'canonical' => long2ip($start).'/'.$prefix];
    }

    public static function overlaps(string $first, string $second): bool
    {
        $firstRange = self::range($first);
        $secondRange = self::range($second);

        return $firstRange['start'] <= $secondRange['end'] && $secondRange['start'] <= $firstRange['end'];
    }

    /**
     * Returns the first IPv4 literal inside the CIDR. Hostnames and IPv6 addresses are ignored.
     *
     * @param  iterable<?string>  $addresses
     */
    public static function firstContainedAddress(string $cidr, iterable $addresses): ?string
    {
        $range = self::range($cidr);
        foreach ($addresses as $address) {
            if (filled($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ip = self::ip($address);
                if ($ip >= $range['start'] && $ip <= $range['end']) {
                    return $address;
                }
            }
        }

        return null;
    }

    /** IPv4 address of the Coolify host, which Sentinel's cluster firewall must never drop. */
    public static function coolifyHostAddress(): ?string
    {
        return InstanceSettings::query()->whereKey(0)->value('public_ipv4');
    }

    private static function ip(string $ip): int
    {
        return (int) sprintf('%u', ip2long($ip));
    }
}
