<?php

namespace App\Actions\Database;

use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

class ValidatePostgresqlWalGImage
{
    use AsAction;

    public function handle(string $image, ?int $expectedMajorVersion = null): int
    {
        if (! preg_match('/\Aghcr\.io\/coollabsio\/postgres-walg:(16|17|18)\z/', $image, $matches)) {
            throw ValidationException::withMessages([
                'image' => 'PITR requires a supported Coolify PostgreSQL WAL-G image (versions 16, 17, or 18).',
            ]);
        }

        $majorVersion = (int) $matches[1];
        if ($expectedMajorVersion !== null && $majorVersion !== $expectedMajorVersion) {
            throw ValidationException::withMessages([
                'image' => "The PostgreSQL WAL-G image must remain on major version {$expectedMajorVersion}.",
            ]);
        }

        return $majorVersion;
    }
}
