<?php

it('returns empty commands when traffic analytics is disabled', function () {
    expect(traefikAccessLogCommands(false))->toBe([]);
});

it('returns JSON access log + Cloudflare header capture flags when enabled', function () {
    $cmds = traefikAccessLogCommands(true);
    expect($cmds)->toContain('--accesslog=true')
        ->toContain('--accesslog.filepath=/traefik/access.log')
        ->toContain('--accesslog.format=json')
        ->toContain('--accesslog.fields.headers.names.Cf-Connecting-Ip=keep')
        ->toContain('--accesslog.fields.headers.names.Cf-Ipcountry=keep')
        ->toContain('--accesslog.fields.headers.names.Cf-Cache-Status=keep')
        ->toContain('--accesslog.fields.headers.names.Cf-Verified-Bot=keep')
        ->toContain('--accesslog.fields.headers.names.Cf-Ray=keep');
});
