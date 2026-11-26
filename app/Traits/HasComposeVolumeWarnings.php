<?php

namespace App\Traits;

/**
 * Keeps the Compose volume warnings of the last parse on the model instance. The warnings are not
 * saved to the database. The deployment job and the service start actions show them to the user.
 */
trait HasComposeVolumeWarnings
{
    /** @var list<string> */
    private array $composeVolumeWarnings = [];

    public function resetComposeVolumeWarnings(): void
    {
        $this->composeVolumeWarnings = [];
    }

    public function addComposeVolumeWarning(string $warning): void
    {
        if (! in_array($warning, $this->composeVolumeWarnings, true)) {
            $this->composeVolumeWarnings[] = $warning;
        }
    }

    /**
     * @return list<string>
     */
    public function composeVolumeWarnings(): array
    {
        return $this->composeVolumeWarnings;
    }
}
