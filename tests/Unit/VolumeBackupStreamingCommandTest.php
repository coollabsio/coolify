<?php

it('does not pass --quiet to mc pipe in volume streaming backup command', function () {
    $jobSource = file_get_contents(__DIR__.'/../../app/Jobs/VolumeBackupJob.php');

    expect($jobSource)
        ->toContain('mc pipe')
        ->not->toContain('mc pipe --quiet');
});
