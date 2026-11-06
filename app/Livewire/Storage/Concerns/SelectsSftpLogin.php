<?php

namespace App\Livewire\Storage\Concerns;

use App\Models\PrivateKey;

/**
 * The login fields of an SFTP storage: a password, or a private key of the storage team.
 */
trait SelectsSftpLogin
{
    /**
     * "password" or "key".
     */
    public string $sftpAuth = 'password';

    public ?int $privateKeyId = null;

    /**
     * Replaces the `secret` and `private_key_id` rules of connectionRules() with rules for the login method.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function withSftpLoginRules(array $rules): array
    {
        if (! array_key_exists('private_key_id', $rules)) {
            return $rules;
        }

        $usesKey = $this->sftpAuth === 'key';
        $keyRules = $rules['private_key_id'];
        unset($rules['private_key_id']);
        $rules['sftpAuth'] = ['required', 'in:password,key'];
        $rules['privateKeyId'] = [$usesKey ? 'required' : 'nullable', ...array_slice($keyRules, 1)];
        $rules['secret'] = [$usesKey ? 'nullable' : 'required', 'string', 'max:255'];

        return $rules;
    }

    /**
     * The private key ID to save: only when the storage logs in with a key.
     */
    protected function selectedPrivateKeyId(): ?int
    {
        return $this->sftpAuth === 'key' ? $this->privateKeyId : null;
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    protected function privateKeyOptions(int $teamId): array
    {
        return PrivateKey::query()
            ->where('team_id', $teamId)
            ->where('is_git_related', false)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (PrivateKey $key): array => ['value' => $key->id, 'label' => $key->name])
            ->all();
    }

    /**
     * The public key of the selected private key, to add to authorized_keys on the SFTP server.
     */
    protected function selectedPublicKey(int $teamId): ?string
    {
        if ($this->sftpAuth !== 'key' || $this->privateKeyId === null) {
            return null;
        }

        return PrivateKey::query()->where('team_id', $teamId)->find($this->privateKeyId)?->getPublicKey();
    }
}
