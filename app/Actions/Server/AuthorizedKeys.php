<?php

namespace App\Actions\Server;

use App\Models\PrivateKey;
use App\Models\Server;
use App\Services\Dns\DnsResolver;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * Adds and removes public keys in ~/.ssh/authorized_keys of the SSH user. Both operations are safe to run again.
 */
class AuthorizedKeys
{
    public static function canRunOn(Server $server): bool
    {
        return ! $server->isTransferredAway() && ! $server->skipServer();
    }

    /** The exit code of add() when ~/.ssh/authorized_keys has no line with the old key. */
    public const OLD_KEY_NOT_FOUND = 3;

    /**
     * Adds the new key with the options of every line of the old key. The old key stays until remove().
     * Throws a RuntimeException with the code OLD_KEY_NOT_FOUND when the file has no line with the old key.
     */
    public static function add(Server $server, PrivateKey $privateKey, PrivateKey $oldKey): void
    {
        self::assertCanRunOn($server);
        self::runLocked($server, [
            'f="$HOME/.ssh/authorized_keys"',
            'tmp=$(mktemp "$f.cpk-XXXXXX")',
            ...self::rotatedLinesCommands($privateKey, $oldKey, '"$tmp"', '{ echo "The old key is not in ~/.ssh/authorized_keys of the SSH user." >&2; exit '.self::OLD_KEY_NOT_FOUND.'; }'),
            // A last line without a final newline would be joined with the new line.
            'if [ -s "$f" ] && [ -n "$(tail -c1 "$f")" ]; then echo >> "$f"; fi',
            // Do not add an unrestricted fallback, including on retry.
            'while IFS= read -r entry; do grep -qxF -- "$entry" "$f" || printf \'%s\\n\' "$entry" >> "$f"; done < "$tmp"',
        ]);
    }

    /**
     * Undoes add() after the new key could not log in: removes only the exact lines that add() wrote.
     * Other lines stay, also lines that contain the new key. Returns false when another server record
     * for the same host and user uses the new key.
     */
    public static function undoAdd(Server $server, PrivateKey $privateKey, PrivateKey $oldKey): bool
    {
        self::assertCanRunOn($server);
        if (self::isUsedOnSameHost($server, $privateKey->publicKeyBody(), $privateKey->id)) {
            return false;
        }

        self::runLocked($server, [
            'f="$HOME/.ssh/authorized_keys"',
            'if [ ! -f "$f" ]; then exit 0; fi',
            'tmp=$(mktemp "$f.cpk-XXXXXX")',
            'cp -p "$f" "$tmp"',
            // Without a line of the old key, add() wrote nothing.
            ...self::rotatedLinesCommands($privateKey, $oldKey, '"$tmp.lines"', 'exit 0'),
            'rc=0',
            'grep -vxF -f "$tmp.lines" -- "$f" > "$tmp" || rc=$?',
            'if [ "$rc" -gt 1 ]; then exit 1; fi',
            'mv -f "$tmp" "$f"',
        ]);

        return true;
    }

    /**
     * Writes to $output the lines that add() writes: the new key with the options of each line of the old key.
     * Runs $notFound when the file has no line with the old key.
     *
     * @return list<string>
     */
    private static function rotatedLinesCommands(PrivateKey $privateKey, PrivateKey $oldKey, string $output, string $notFound): array
    {
        $line = escapeshellarg($privateKey->authorizedKeysLine());
        $oldBody = escapeshellarg($oldKey->publicKeyBody());
        // Tokenize outside quoted options; never interpret options as shell commands.
        $script = <<<'AWK'
function token(    start,c,quoted,escaped) {
    while (substr($0,pos,1) ~ /[ \t]/ && pos <= length($0)) pos++
    start=pos; quoted=0; escaped=0
    while (pos <= length($0)) {
        c=substr($0,pos,1)
        if (escaped) escaped=0
        else if (c == "\\" && quoted) escaped=1
        else if (c == "\"") quoted=!quoted
        else if (!quoted && c ~ /[ \t]/) break
        pos++
    }
    return substr($0,start,pos-start)
}
{
    pos=1
    first=token()
    if (first == "" || substr(first,1,1) == "#") next
    prefix=""
    if (first != type) { prefix=first " "; first=token() }
    data=token()
    if (first == type && data == key) { print prefix replacement; found=1 }
}
END { if (!found) exit 1 }
AWK;

        return [
            "old={$oldBody}",
            'type=${old%% *}; key=${old#* }',
            "awk -v type=\"\$type\" -v key=\"\$key\" -v replacement={$line} ".escapeshellarg($script)." \"\$f\" > {$output} || {$notFound}",
        ];
    }

    /**
     * Removes every line with the public key, also lines that cloud-init or a cloud provider added.
     * Returns false when another server record for the same host and user still uses the key.
     */
    public static function remove(Server $server, PrivateKey $privateKey): bool
    {
        return self::removePublicKey($server, $privateKey->publicKeyBody(), $privateKey->id);
    }

    /**
     * Like remove(), for a key whose record may be deleted already.
     *
     * @param  string  $publicKeyBody  The key type and the base64 data, without a comment.
     */
    public static function removePublicKey(Server $server, string $publicKeyBody, ?int $privateKeyId = null): bool
    {
        self::assertCanRunOn($server);
        if (self::isUsedOnSameHost($server, $publicKeyBody, $privateKeyId)) {
            return false;
        }
        $body = escapeshellarg($publicKeyBody);

        self::runLocked($server, [
            'f="$HOME/.ssh/authorized_keys"',
            'if [ ! -f "$f" ]; then exit 0; fi',
            'tmp=$(mktemp "$f.cpk-XXXXXX")',
            // Preserve the existing owner and mode before replacing the file atomically.
            'cp -p "$f" "$tmp"',
            'rc=0',
            "grep -vF -- {$body} \"\$f\" > \"\$tmp\" || rc=\$?",
            'if [ "$rc" -gt 1 ]; then exit 1; fi',
            'mv -f "$tmp" "$f"',
        ]);

        return true;
    }

    /** The SHA256 fingerprint in the format of PrivateKey::$fingerprint, or null when the key cannot be read. */
    public static function fingerprint(string $publicKeyBody): ?string
    {
        try {
            return PublicKeyLoader::load($publicKeyBody)->getFingerprint('sha256');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * All edits share a lock in the remote SSH home, also when server records use different aliases or keys.
     * mkdir is atomic and available on supported hosts without requiring flock.
     */
    private static function runLocked(Server $server, array $commands): void
    {
        instant_remote_process([
            'if [ ! -d "$HOME/.ssh" ]; then mkdir -p "$HOME/.ssh" && chmod 700 "$HOME/.ssh"; fi',
            'lock="$HOME/.ssh/.coolify-authorized-keys.lock"',
            'attempt=0',
            // A lock older than 10 minutes is left from an edit that crashed. Edits take a few seconds.
            'find "$lock" -maxdepth 0 -type d -mmin +10 -exec rmdir {} \\; 2>/dev/null || true',
            'until mkdir "$lock" 2>/dev/null; do',
            'attempt=$((attempt + 1))',
            'if [ "$attempt" -ge 30 ]; then echo "Another authorized_keys edit is running; try again later." >&2; exit 1; fi',
            'sleep 1',
            'done',
            'tmp=""',
            "trap '[ -z \"\$tmp\" ] || rm -f \"\$tmp\" \"\$tmp.lines\"; rmdir \"\$lock\"' EXIT",
            "trap 'exit 1' HUP INT TERM",
            ...$commands,
        ], $server, no_sudo: true);
    }

    private static function isUsedOnSameHost(Server $server, string $publicKeyBody, ?int $privateKeyId): bool
    {
        $fingerprint = self::fingerprint($publicKeyBody);
        // Other teams count only with the same key material: removing it would lock them out.
        // Keys saved before fingerprints existed have none stored, so compare their key material.
        $others = Server::query()
            ->with('privateKey')
            ->where('user', $server->user)
            ->whereKeyNot($server->id)
            ->where(fn ($query) => $query->when($privateKeyId, fn ($query) => $query->where('private_key_id', $privateKeyId))
                ->orWhereHas('privateKey', fn ($keys) => $keys->where('fingerprint', $fingerprint)->orWhereNull('fingerprint')))
            ->with('settings:id,server_id,ssh_host_key')
            ->get(['id', 'ip', 'private_key_id'])
            ->filter(fn (Server $other) => ($privateKeyId && $other->private_key_id === $privateKeyId)
                || ($other->privateKey && $fingerprint
                    && ($other->privateKey->fingerprint ?: PrivateKey::generateFingerprint($other->privateKey->private_key)) === $fingerprint));
        $addresses = [];
        foreach ($others as $other) {
            $host = strtolower(rtrim(Server::normalizeIp($server->ip), '.'));
            $otherHost = strtolower(rtrim(Server::normalizeIp($other->ip), '.'));
            if ($host === $otherHost) {
                return true;
            }
            // Two addresses of one machine (for example a public and a Tailscale IP) show the same host key.
            // Without a pinned host key on both records, it is not known that these are different machines.
            $hostKeys = self::hostKeys($server);
            $otherHostKeys = self::hostKeys($other);
            if ($hostKeys === [] || $otherHostKeys === [] || array_intersect($hostKeys, $otherHostKeys) !== []) {
                return true;
            }
            $addresses[$host] ??= self::hostAddresses($host);
            $addresses[$otherHost] ??= self::hostAddresses($otherHost);
            // An unresolved hostname cannot prove that these are different machines. Keep the key.
            if ($addresses[$host] === [] || $addresses[$otherHost] === []
                || array_intersect($addresses[$host], $addresses[$otherHost]) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> The pinned SSH host keys of the server, as "type base64" lines. */
    private static function hostKeys(Server $server): array
    {
        return array_values(array_filter(array_map(
            fn (string $line) => implode(' ', array_slice(preg_split('/\s+/', trim($line)), 0, 2)),
            preg_split('/\R/', (string) $server->settings?->ssh_host_key),
        )));
    }

    /** @return list<string> */
    private static function hostAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $resolver = trim(explode(',', (string) instanceSettings()->custom_dns_servers)[0]);
        if ($resolver === '') {
            return [];
        }
        $addresses = [];
        foreach ([DnsResolver::TYPE_A, DnsResolver::TYPE_AAAA] as $type) {
            try {
                $records = queryDnsServer($resolver, $host, $type, timeout: 2);
                if ($records === false) {
                    return [];
                }
                foreach ($records as $record) {
                    $addresses[] = Server::normalizeIp($record->getData());
                }
            } catch (\Throwable) {
                return [];
            }
        }

        return $addresses;
    }

    private static function assertCanRunOn(Server $server): void
    {
        if (! self::canRunOn($server)) {
            throw new \RuntimeException('Coolify does not run commands on this server.');
        }
    }
}
