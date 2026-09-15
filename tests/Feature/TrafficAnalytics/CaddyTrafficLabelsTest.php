<?php

it('adds no traffic labels to caddy sites when disabled', function () {
    $labels = fqdnLabelsForCaddy('coolify', 'app-uuid', collect(['https://example.com']), is_traffic_analytics_enabled: false);
    expect($labels->filter(fn ($l) => str_contains($l, 'log_append'))->isEmpty())->toBeTrue();
});

it('stamps coolify_app_id and JSON access log on each caddy site when enabled', function () {
    $labels = fqdnLabelsForCaddy('coolify', 'app-uuid', collect(['https://example.com']), is_traffic_analytics_enabled: true);
    expect($labels->contains('caddy_0.log_append=coolify_app_id app-uuid'))->toBeTrue();
    expect($labels->contains('caddy_0.log.output=file /traffic/access.log'))->toBeTrue();
    expect($labels->contains('caddy_0.log.format=json'))->toBeTrue();
});

it('emits lumberjack roll directives on each caddy site when enabled', function () {
    $labels = fqdnLabelsForCaddy('coolify', 'app-uuid', collect(['https://example.com']), is_traffic_analytics_enabled: true);
    expect($labels->contains('caddy_0.log.output.roll_size=20MiB'))->toBeTrue();
    expect($labels->contains('caddy_0.log.output.roll_keep=5'))->toBeTrue();
    expect($labels->contains('caddy_0.log.output.roll_keep_for=168h'))->toBeTrue();
});

it('omits lumberjack roll directives when disabled', function () {
    $labels = fqdnLabelsForCaddy('coolify', 'app-uuid', collect(['https://example.com']), is_traffic_analytics_enabled: false);
    expect($labels->filter(fn ($l) => str_contains($l, 'roll_'))->isEmpty())->toBeTrue();
});
