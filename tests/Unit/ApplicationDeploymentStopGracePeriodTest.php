<?php

it('adds an explicitly configured stop grace period to generated compose services', function () {
    $source = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    expect($source)
        ->toContain('if ($this->application->settings->stop_grace_period !== null) {')
        ->toContain("['stop_grace_period'] = \$this->application->settings->stopGracePeriodSeconds().'s'");
});
