<?php

namespace App\Models;

use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

class AgeKey extends BaseModel
{
    protected $fillable = [
        'name',
        'description',
        'public_key',
        'team_id',
    ];

    protected static function booted()
    {
        static::saving(function (AgeKey $key) {
            $key->public_key = trim($key->public_key);

            if (! self::isValidPublicKey($key->public_key)) {
                throw ValidationException::withMessages([
                    'public_key' => ['The age public key is invalid. It should look like age1...'],
                ]);
            }
        });
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $teamId = currentTeam()->id;
        $selectArray = collect($select)->concat(['id']);

        return self::whereTeamId($teamId)->select($selectArray->all());
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function scheduledDatabaseBackups()
    {
        return $this->hasMany(ScheduledDatabaseBackup::class);
    }

    public function isInUse()
    {
        return $this->scheduledDatabaseBackups()->exists();
    }

    public function safeDelete()
    {
        if (! $this->isInUse()) {
            $this->delete();

            return true;
        }

        return false;
    }

    public static function isValidPublicKey(string $publicKey): bool
    {
        return (bool) preg_match('/^age1[a-z0-9]{58}$/', trim($publicKey));
    }

    /**
     * Generates a fresh age keypair. The private key is returned only in this
     * array for one-time display to the caller — it is never persisted by
     * this model. Only the public key should be saved (e.g. via ::create()).
     *
     * @return array{name: string, description: string, private_key: string, public_key: string}
     */
    public static function generateNewKeyPair(): array
    {
        $result = Process::run('age-keygen');
        if (! $result->successful()) {
            throw new \Exception('Failed to generate age key pair: '.$result->errorOutput());
        }

        $privateKey = null;
        $publicKey = null;
        foreach (explode("\n", $result->output()) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '# public key: ')) {
                $publicKey = trim(str_replace('# public key: ', '', $line));
            } elseif (str_starts_with($line, 'AGE-SECRET-KEY-')) {
                $privateKey = $line;
            }
        }

        if (blank($privateKey) || blank($publicKey)) {
            throw new \Exception('Failed to parse generated age key pair.');
        }

        return [
            'name' => generate_random_name(),
            'description' => 'Created by Coolify',
            'private_key' => $privateKey,
            'public_key' => $publicKey,
        ];
    }
}
