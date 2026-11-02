<?php

namespace App\Console\Commands;

use App\Models\GitlabApp;
use App\Models\OauthSetting;
use App\Models\ServerSetting;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

class ReencryptWithAppKey extends Command
{
    protected $signature = 'app-key:reencrypt
        {--dry-run : Count the values that use an old key without changing them}
        {--force : Re-encrypt without asking for confirmation}';

    protected $description = 'Re-encrypt stored secrets with the current APP_KEY after a key rotation (old keys in APP_PREVIOUS_KEYS)';

    /**
     * Columns that model accessors encrypt instead of an encrypted cast.
     * A guard test fails when a model encrypts data in a way this command does not know.
     *
     * @var array<class-string<Model>, list<string>>
     */
    public const ACCESSOR_ENCRYPTED_COLUMNS = [
        GitlabApp::class => ['webhook_token'],
        OauthSetting::class => ['client_secret'],
    ];

    private Encrypter $currentKeyOnly;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->currentKeyOnly = new Encrypter(Crypt::getKey(), config('app.cipher'));

        if (Crypt::getPreviousKeys() === []) {
            $this->warn('APP_PREVIOUS_KEYS is empty. Values encrypted with an old key cannot be decrypted.');
        }

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Re-encrypt all stored secrets with the current APP_KEY?')) {
            return self::FAILURE;
        }

        $rows = [];
        $reencrypted = 0;
        $undecryptable = 0;
        foreach (self::encryptedColumns() as $class => $columns) {
            foreach ($columns as $column) {
                [$changed, $failed] = $this->reencryptColumn(new $class, $column, $dryRun);
                $reencrypted += $changed;
                $undecryptable += $failed;
                if ($changed + $failed > 0) {
                    $rows[] = [class_basename($class), $column, $changed, $failed];
                }
            }
        }
        $sentinelTokens = $this->regenerateSentinelTokens($dryRun);

        if ($rows !== []) {
            $this->table(['Model', 'Column', $dryRun ? 'Uses an old key' : 'Re-encrypted', 'Undecryptable'], $rows);
        }

        if ($dryRun) {
            $this->info("{$reencrypted} values and {$sentinelTokens} Sentinel tokens use an old key. Nothing was changed.");
        } else {
            $this->info("Re-encrypted {$reencrypted} values.");
            $this->info("Regenerated {$sentinelTokens} Sentinel tokens. Sentinel restarts on the servers where it is enabled.");
        }

        if ($undecryptable > 0) {
            $this->error("{$undecryptable} values cannot be decrypted with APP_KEY or APP_PREVIOUS_KEYS. Do not remove APP_PREVIOUS_KEYS until you fix them.");

            return self::FAILURE;
        }

        if (! $dryRun) {
            $this->info('You can remove APP_PREVIOUS_KEYS when queued jobs finished. Sessions, invitation links, and old backups still need the previous key.');
        }

        return self::SUCCESS;
    }

    /**
     * Every model column that holds a Laravel encrypted payload.
     *
     * @return array<class-string<Model>, list<string>>
     */
    public static function encryptedColumns(): array
    {
        $columns = [];
        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $file) {
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $castColumns = collect((new $class)->getCasts())
                ->filter(fn ($cast) => is_string($cast) && Str::contains($cast, 'encrypted', ignoreCase: true))
                ->keys()
                ->all();
            $encrypted = array_values(array_unique([...$castColumns, ...(self::ACCESSOR_ENCRYPTED_COLUMNS[$class] ?? [])]));
            if ($encrypted !== []) {
                $columns[$class] = $encrypted;
            }
        }
        ksort($columns);

        return $columns;
    }

    /**
     * Decrypts and encrypts the raw string, so serialized (encrypt()) and plain (encryptString())
     * payloads keep their format. Plaintext legacy values are left as they are.
     *
     * @return array{int, int} The re-encrypted and the undecryptable value counts.
     */
    private function reencryptColumn(Model $model, string $column, bool $dryRun): array
    {
        $schema = Schema::connection($model->getConnectionName());
        if (! $schema->hasTable($model->getTable()) || ! $schema->hasColumn($model->getTable(), $column)) {
            return [0, 0];
        }

        $keyName = $model->getKeyName();
        $changed = 0;
        $failed = 0;
        $model->newQueryWithoutScopes()->toBase()
            ->select([$keyName, $column])
            ->whereNotNull($column)
            ->chunkById(500, function ($rows) use ($model, $keyName, $column, $dryRun, &$changed, &$failed) {
                foreach ($rows as $row) {
                    $payload = $row->{$column};
                    if (! $this->usesOldKey($payload)) {
                        continue;
                    }

                    try {
                        $plaintext = Crypt::decryptString($payload);
                    } catch (DecryptException) {
                        $failed++;

                        continue;
                    }

                    if (! $dryRun) {
                        // Compare-and-set, so a value saved during the run is not overwritten.
                        $model->newQueryWithoutScopes()->toBase()
                            ->where($keyName, $row->{$keyName})
                            ->where($column, $payload)
                            ->update([$column => Crypt::encryptString($plaintext)]);
                    }
                    $changed++;
                }
            }, $keyName);

        return [$changed, $failed];
    }

    /**
     * A Sentinel token is an encrypted payload itself, and Sentinel sends it back on every push.
     * Saving a new token restarts Sentinel with it (see ServerSetting::booted()).
     */
    private function regenerateSentinelTokens(bool $dryRun): int
    {
        $count = 0;
        ServerSetting::query()->with('server')->chunkById(100, function (Collection $settings) use ($dryRun, &$count) {
            foreach ($settings as $setting) {
                try {
                    $token = $setting->sentinel_token;
                } catch (DecryptException) {
                    continue;
                }

                if ($setting->server === null || ! $this->usesOldKey($token)) {
                    continue;
                }

                if (! $dryRun) {
                    $setting->generateSentinelToken();
                }
                $count++;
            }
        });

        return $count;
    }

    private function usesOldKey(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $payload = json_decode(base64_decode($value, true) ?: '', true);
        if (! is_array($payload) || ! isset($payload['iv'], $payload['value'], $payload['mac'])) {
            return false;
        }

        try {
            $this->currentKeyOnly->decryptString($value);

            return false;
        } catch (DecryptException) {
            return true;
        }
    }
}
