<?php

namespace App\Actions\Server;

use App\Models\PrivateKey;
use App\Models\Server;

/**
 * Adds and removes public keys in ~/.ssh/authorized_keys of the SSH user. Both operations are safe to run again.
 */
class AuthorizedKeys
{
    public static function canRunOn(Server $server): bool
    {
        return ! $server->isTransferredAway() && ! $server->skipServer();
    }

    public static function add(Server $server, PrivateKey $privateKey): void
    {
        self::assertCanRunOn($server);
        $body = escapeshellarg($privateKey->publicKeyBody());
        $line = escapeshellarg($privateKey->authorizedKeysLine());

        instant_remote_process([
            'f="$HOME/.ssh/authorized_keys"',
            'if [ ! -d "$HOME/.ssh" ]; then mkdir -p "$HOME/.ssh" && chmod 700 "$HOME/.ssh"; fi',
            'if [ ! -f "$f" ]; then touch "$f" && chmod 600 "$f"; fi',
            "if grep -qF -- {$body} \"\$f\"; then exit 0; fi",
            'if [ -s "$f" ] && [ -n "$(tail -c1 "$f")" ]; then echo >> "$f"; fi',
            "printf '%s\\n' {$line} >> \"\$f\"",
        ], $server, no_sudo: true);
    }

    /**
     * Removes every line with the public key, also lines that cloud-init or a cloud provider added.
     * Returns false when another server record for the same host and user still uses the key.
     */
    public static function remove(Server $server, PrivateKey $privateKey): bool
    {
        self::assertCanRunOn($server);
        $stillUsed = Server::query()
            ->where('private_key_id', $privateKey->id)
            ->where('ip', $server->ip)
            ->where('port', $server->port)
            ->where('user', $server->user)
            ->whereKeyNot($server->id)
            ->exists();
        if ($stillUsed) {
            return false;
        }
        $body = escapeshellarg($privateKey->publicKeyBody());

        instant_remote_process([
            'f="$HOME/.ssh/authorized_keys"',
            'if [ ! -f "$f" ]; then exit 0; fi',
            'rc=0',
            "grep -vF -- {$body} \"\$f\" > \"\$f.cpk-tmp\" || rc=\$?",
            'if [ "$rc" -gt 1 ]; then rm -f "$f.cpk-tmp"; exit 1; fi',
            // cat keeps the owner, mode, and inode of the file.
            'cat "$f.cpk-tmp" > "$f"',
            'rm -f "$f.cpk-tmp"',
        ], $server, no_sudo: true);

        return true;
    }

    private static function assertCanRunOn(Server $server): void
    {
        if (! self::canRunOn($server)) {
            throw new \RuntimeException('Coolify does not run commands on this server.');
        }
    }
}
