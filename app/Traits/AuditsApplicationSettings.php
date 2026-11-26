<?php

namespace App\Traits;

use App\Models\Application;

trait AuditsApplicationSettings
{
    /**
     * Save the application's settings and record `ui.application.settings_updated` with the
     * names of the changed fields. Nothing is recorded when no setting changed.
     */
    private function saveApplicationSettingsWithAudit(Application $application): void
    {
        $settings = $application->settings;
        $changedFields = auditChangedFields($settings);

        $settings->save();

        if ($changedFields === []) {
            return;
        }

        auditLog('ui.application.settings_updated', [
            'team_id' => $application->team()?->id,
            'application_uuid' => $application->uuid,
            'application_name' => $application->name,
            'changed_fields' => $changedFields,
        ]);
    }
}
