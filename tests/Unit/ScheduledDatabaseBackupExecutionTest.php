<?php

use App\Models\ScheduledDatabaseBackupExecution;

it('casts finished_at to a datetime', function () {
    expect((new ScheduledDatabaseBackupExecution)->getCasts())
        ->toHaveKey('finished_at', 'datetime');
});
