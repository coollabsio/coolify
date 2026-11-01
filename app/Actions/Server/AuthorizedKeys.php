<?php

namespace App\Actions\Server;

use App\Models\PrivateKey;
use App\Models\Server;
use App\Services\Dns\DnsResolver;

/**
 * Adds and removes public keys in ~/.ssh/authorized_keys of the SSH user. Both operations are safe to run again.
 */
class AuthorizedKeys
{
    public static function canRunOn(Server $server): bool
    {
        return ! $server->isTransferredAway() && ! $server->skipServer();
    }

    public static function add(Server $server, PrivateKey $privateKey, ?PrivateKey $oldKey = null): void
    {
        self::assertCanRunOn($server);
        $body = escapeshellarg($privateKey->publicKeyBody());
        $line = escapeshellarg($privateKey->authorizedKeysLine());

        if ($oldKey) {
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
            self::runLocked($server, [
                'f="$HOME/.ssh/authorized_keys"',
                'tmp=$(mktemp "$f.cpk-XXXXXX")',
                "old={$oldBody}",
                'type=${old%% *}; key=${old#* }',
                "awk -v type=\"\$type\" -v key=\"\$key\" -v replacement={$line} ".escapeshellarg($script).' "$f" > "$tmp" || { echo "Cannot locate the old authorized key; rotation stopped." >&2; exit 1; }',
                // Do not add an unrestricted fallback, including on retry.
                'while IFS= read -r entry; do grep -qxF -- "$entry" "$f" || printf \'%s\\n\' "$entry" >> "$f"; done < "$tmp"',
            ]);

            return;
        }

        self::runLocked($server, [
            'f="$HOME/.ssh/authorized_keys"',
            'if [ ! -f "$f" ]; then touch "$f" && chmod 600 "$f"; fi',
            "if grep -qF -- {$body} \"\$f\"; then exit 0; fi",
            'if [ -s "$f" ] && [ -n "$(tail -c1 "$f")" ]; then echo >> "$f"; fi',
            "printf '%s\\n' {$line} >> \"\$f\"",
        ]);
    }

    /**
     * Removes every line with the public key, also lines that cloud-init or a cloud provider added.
     * Returns false when another server record for the same host and user still uses the key.
     */
    public static function remove(Server $server, PrivateKey $privateKey): bool
    {
        self::assertCanRunOn($server);
        if (self::isUsedOnSameHost($server, $privateKey)) {
            return false;
        }
        $body = escapeshellarg($privateKey->publicKeyBody());

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
            'until mkdir "$lock" 2>/dev/null; do',
            'attempt=$((attempt + 1))',
            'if [ "$attempt" -ge 30 ]; then echo "Another authorized_keys edit is running; try again later." >&2; exit 1; fi',
            'sleep 1',
            'done',
            'tmp=""',
            "trap '[ -z \"\$tmp\" ] || rm -f \"\$tmp\"; rmdir \"\$lock\"' EXIT",
            "trap 'exit 1' HUP INT TERM",
            ...$commands,
        ], $server, no_sudo: true);
    }

    private static function isUsedOnSameHost(Server $server, PrivateKey $privateKey): bool
    {
        $fingerprint = $privateKey->fingerprint ?: PrivateKey::generateFingerprint($privateKey->private_key);
        $others = Server::query()
            ->where('user', $server->user)
            ->whereKeyNot($server->id)
            ->where(fn ($query) => $query->where('private_key_id', $privateKey->id)
                ->orWhereHas('privateKey', fn ($keys) => $keys->where('fingerprint', $fingerprint)->orWhereNull('fingerprint')))
            ->get(['id', 'ip']);
        $addresses = [];
        foreach ($others as $other) {
            $host = strtolower(rtrim(Server::normalizeIp($server->ip), '.'));
            $otherHost = strtolower(rtrim(Server::normalizeIp($other->ip), '.'));
            if ($host === $otherHost) {
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
