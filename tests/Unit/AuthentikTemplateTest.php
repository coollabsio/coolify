<?php

use Symfony\Component\Yaml\Yaml;

it('fixes custom template permissions before Authentik starts', function () {
    $compose = Yaml::parse(file_get_contents(base_path('templates/compose/authentik.yaml')));

    $services = $compose['services'];

    expect($services['authentik-permissions'])
        ->toMatchArray([
            'exclude_from_hc' => true,
            'user' => 'root',
            'restart' => false,
        ]);

    expect($services['authentik-permissions']['command'])
        ->toContain('chown -R authentik:authentik /templates')
        ->toContain('chmod -R u+rwX,g+rwX /templates');

    expect($services['authentik-server']['depends_on']['authentik-permissions']['condition'])
        ->toBe('service_completed_successfully');

    expect($services['authentik-worker']['depends_on']['authentik-permissions']['condition'])
        ->toBe('service_completed_successfully');
});
