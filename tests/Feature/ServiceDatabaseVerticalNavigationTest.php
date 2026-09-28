<?php

it('refreshes backup executions from backup broadcasts on the current team channel', function () {
    $serviceExecutions = file_get_contents(app_path('Livewire/Project/Service/BackupExecutions.php'));
    $serviceBackups = file_get_contents(app_path('Livewire/Project/Service/VolumeBackup/Index.php'));
    $databaseExecutions = file_get_contents(app_path('Livewire/Project/Database/BackupExecutions.php'));
    $databaseBackupJob = file_get_contents(app_path('Jobs/DatabaseBackupJob.php'));

    expect($serviceExecutions)
        ->toContain('echo-private:team.{$teamId},BackupCreated')
        ->toContain("=> '\$refresh'")
        ->and($serviceBackups)
        ->toContain('echo-private:team.{$teamId},BackupCreated')
        ->and($databaseExecutions)
        ->toContain('$teamId = currentTeam()->id')
        ->not->toContain('$userId = Auth::id()')
        ->and(strpos($databaseBackupJob, "'finished_at' => Carbon::now()->toImmutable()"))
        ->toBeLessThan(strrpos($databaseBackupJob, 'BackupCreated::dispatch($this->team->id)'));
});
