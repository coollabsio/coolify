<?php

use App\Models\Service;
use App\Models\ServiceApplication;

it('resolves the wordpress template port from service_type even when the display name differs', function () {
    expect(data_get(get_service_templates(), 'wordpress-without-database.port'))->toBe('80');

    $service = new Service([
        'name' => 'api-smoke-wp',
        'service_type' => 'wordpress-without-database',
        'docker_compose_raw' => <<<'YAML'
services:
  wordpress:
    image: wordpress:latest
    environment:
      - SERVICE_URL_WORDPRESS
YAML,
    ]);

    expect($service->getRequiredPort())->toBe(80);

    $app = new ServiceApplication(['name' => 'wordpress']);
    $app->setRelation('service', $service);

    expect($app->getRequiredPort())->toBe(80);
});
