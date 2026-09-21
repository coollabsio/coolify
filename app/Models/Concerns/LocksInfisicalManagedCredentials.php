<?php

namespace App\Models\Concerns;

use App\Exceptions\InfisicalManagedVariableException;
use App\Services\Infisical\InfisicalLock;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Locks a managed database's credential COLUMNS while Infisical is armed.
 *
 * Seven of the eight engines store credentials as model columns rather than
 * environment_variables rows, so the variable-model hooks do not reach them.
 * StandaloneRedis is excluded: it has no credential column at all — its
 * `redis_password` column was dropped in
 * 2024_10_16_120026_move_redis_password_to_envs and REDIS_PASSWORD /
 * REDIS_USERNAME are ordinary variable rows, already locked by those hooks.
 *
 * Only the listed columns are guarded. Every other field (name, description,
 * image, limits, ports) stays editable — a blanket lock on the whole model
 * would be wrong.
 *
 * Coolify's existing semantics are unchanged: changing a database password
 * takes effect on redeploy and does not rotate a running database.
 *
 * Three storage styles have to be compared uniformly:
 *
 * - an `encrypted` cast (`postgres_password` and friends),
 * - no cast at all, stored plaintext (`mariadb_root_password`),
 * - a get-only `Attribute` with no setter (`mongo_initdb_root_password`).
 *
 * Everything below therefore works on RAW attributes plus a tolerant decrypt,
 * never on the accessor. Reading `$model->mongo_initdb_root_password` runs a
 * getter that self-heals a plaintext value by calling `save()` DURING A READ;
 * calling it from inside the `saving` guard would persist the very write the
 * guard is about to reject.
 *
 * `isDirty()` cannot be used here. Encryption uses a fresh IV per call, so
 * re-assigning an identical plaintext produces different ciphertext and
 * `isDirty()` returns true. Every database General page re-assigns its
 * password field unconditionally in `syncData()`, so an `isDirty()` guard would
 * throw on ANY save — renaming a database, changing a port, editing a memory
 * limit — not just on a credential change.
 */
trait LocksInfisicalManagedCredentials
{
    /**
     * Decrypted credential values as they were when this instance was loaded.
     *
     * @var array<string, string|null>
     */
    public array $infisicalOriginalCredentials = [];

    public static function bootLocksInfisicalManagedCredentials(): void
    {
        static::retrieved(function ($model): void {
            foreach ($model->infisicalManagedColumns() as $column) {
                $model->infisicalOriginalCredentials[$column] = $model->infisicalCredentialPlaintext($column);
            }
        });

        static::saving(function ($model): void {
            $model->guardInfisicalManagedCredentials();
        });
    }

    /**
     * The credential columns this engine stores on the model.
     *
     * @return array<int, string>
     */
    abstract public function infisicalManagedColumns(): array;

    /**
     * Columns whose value must be encrypted by hand on write because the model
     * has no `encrypted` cast and no setter for them.
     *
     * @return array<int, string>
     */
    public function infisicalColumnsRequiringExplicitEncryption(): array
    {
        return [];
    }

    /**
     * Presentation helper for the General pages. Never the control — the
     * `saving` guard below is.
     */
    public function infisicalCredentialsLocked(): bool
    {
        return InfisicalLock::armedForTeam($this->environment?->project?->team_id);
    }

    /**
     * The current in-memory plaintext of a credential column, read from the raw
     * attribute bag so no accessor runs.
     */
    public function infisicalCredentialPlaintext(string $column): ?string
    {
        return $this->infisicalDecodeCredential($this->getAttributes()[$column] ?? null);
    }

    /**
     * Write a value that came down from Infisical into its column.
     *
     * Wrapped in `asSystem()` so it is allowed by the guard regardless of how
     * it is reached; `PullTeamSecrets` already runs inside `asInfisicalPull()`,
     * and nesting is harmless.
     */
    public function infisicalWriteCredential(string $column, string $value): void
    {
        $stored = in_array($column, $this->infisicalColumnsRequiringExplicitEncryption(), true)
            ? encrypt($value)
            : $value;

        InfisicalLock::asSystem(function () use ($column, $stored): void {
            $this->forceFill([$column => $stored])->save();
        });

        $this->infisicalOriginalCredentials[$column] = $value;
    }

    private function guardInfisicalManagedCredentials(): void
    {
        if (InfisicalLock::isSystemWrite()) {
            return;
        }

        if (! InfisicalLock::anyConnectionEnabled()) {
            return;
        }

        $teamId = $this->environment?->project?->team_id;

        if (! InfisicalLock::armedForTeam($teamId)) {
            return;
        }

        foreach ($this->infisicalManagedColumns() as $column) {
            $original = array_key_exists($column, $this->infisicalOriginalCredentials)
                ? $this->infisicalOriginalCredentials[$column]
                // A model rebuilt from a queue payload never fired `retrieved`,
                // so fall back to Eloquent's own original attributes rather
                // than failing open.
                : $this->infisicalDecodeCredential($this->getRawOriginal($column));

            if ($original === null) {
                continue;
            }

            if ($this->infisicalCredentialPlaintext($column) !== $original) {
                throw InfisicalManagedVariableException::forKey($column);
            }
        }
    }

    /**
     * Decrypt if it decrypts, otherwise take the value as stored.
     *
     * The fallback is not defensive padding: `mariadb_root_password` is
     * genuinely plaintext, and a legacy `mongo_initdb_root_password` can be
     * either.
     *
     * Two encodings are in play and they are not interchangeable. The
     * `encrypted` cast writes with `Crypt::encryptString()`, which does NOT
     * serialize; `StandaloneMongodb`'s self-heal writes with the `encrypt()`
     * helper, which DOES. Calling `decrypt()` on a cast-written value emits an
     * `unserialize(): Error at offset 0` warning and returns false rather than
     * throwing — verified against a real row — so the payload is decrypted as
     * a string first and unwrapped only when it is visibly serialized.
     */
    private function infisicalDecodeCredential(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        try {
            $plaintext = Crypt::decryptString((string) $raw);
        } catch (Throwable) {
            return (string) $raw;
        }

        if (preg_match('/^s:\d+:".*";$/s', $plaintext) === 1) {
            $unserialized = @unserialize($plaintext);

            if (is_string($unserialized)) {
                return $unserialized;
            }
        }

        return $plaintext;
    }
}
