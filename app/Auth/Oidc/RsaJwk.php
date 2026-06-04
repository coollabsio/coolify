<?php

namespace App\Auth\Oidc;

use App\Auth\Oidc\Exceptions\OidcTokenException;

class RsaJwk
{
    /**
     * @param  array<string, mixed>  $jwk
     */
    public static function toPem(array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            throw new OidcTokenException('JWKS key is not a valid RSA signing key.');
        }

        $modulus = self::base64UrlDecode($jwk['n']);
        $exponent = self::base64UrlDecode($jwk['e']);

        $sequence = self::encodeSequence(
            self::encodeInteger($modulus).
            self::encodeInteger($exponent)
        );

        $bitString = self::encodeBitString($sequence);
        $algorithmIdentifier = self::encodeSequence(
            self::encodeObjectIdentifier('1.2.840.113549.1.1.1').
            self::encodeNull()
        );

        $subjectPublicKeyInfo = self::encodeSequence($algorithmIdentifier.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".
            chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n").
            "-----END PUBLIC KEY-----\n";
    }

    public static function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new OidcTokenException('Invalid base64url value.');
        }

        return $decoded;
    }

    private static function encodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $encoded = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($encoded)).$encoded;
    }

    private static function encodeInteger(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".self::encodeLength(strlen($value)).$value;
    }

    private static function encodeSequence(string $value): string
    {
        return "\x30".self::encodeLength(strlen($value)).$value;
    }

    private static function encodeBitString(string $value): string
    {
        $value = "\x00".$value;

        return "\x03".self::encodeLength(strlen($value)).$value;
    }

    private static function encodeNull(): string
    {
        return "\x05\x00";
    }

    private static function encodeObjectIdentifier(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $encoded = chr((40 * $parts[0]) + $parts[1]);

        foreach (array_slice($parts, 2) as $part) {
            $stack = [chr($part & 0x7F)];
            $part >>= 7;
            while ($part > 0) {
                array_unshift($stack, chr(($part & 0x7F) | 0x80));
                $part >>= 7;
            }
            $encoded .= implode('', $stack);
        }

        return "\x06".self::encodeLength(strlen($encoded)).$encoded;
    }
}
