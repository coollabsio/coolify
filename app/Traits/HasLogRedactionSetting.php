<?php

namespace App\Traits;

/**
 * The "Environment values in logs" setting of a standalone database.
 */
trait HasLogRedactionSetting
{
    public function initializeHasLogRedactionSetting(): void
    {
        $this->mergeFillable(['redact_all_env_values_in_logs']);
        $this->mergeCasts(['redact_all_env_values_in_logs' => 'boolean']);
    }

    /**
     * Tell if every environment value is hidden in logs. When false, only locked values are hidden.
     */
    public function redactsAllEnvValuesInLogs(): bool
    {
        return $this->redact_all_env_values_in_logs ?? true;
    }
}
