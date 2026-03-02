<?php

use App\Actions\Database\StartDatabaseProxy;

it('disables stream proxy timeout for public database proxy connections', function () {
    $config = StartDatabaseProxy::generateNginxStreamConfig(54320, 'test-db', 5432);

    expect($config)->toContain('listen 54320;');
    expect($config)->toContain('proxy_pass test-db:5432;');
    expect($config)->toContain('proxy_timeout 0;');
});
