<?php

use App\Models\Service;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('declarativeFields returns mapped fields from compose metadata', function () {
    $compose = "
services:
  web:
    image: nginx
x-coolify:
  fields:
    api_key:
      key: API_KEY
      label: 'My API Key'
      default: 'default-token'
      rules: 'required|string'
    ";

    $service = new Service();
    $service->docker_compose_raw = $compose;

    $fields = $service->declarativeFields();

    expect($fields)->toBeInstanceOf(Collection::class);
    expect($fields->count())->toBe(1);
    
    $field = $fields->first();
    expect($field['label'])->toBe('My API Key');
    expect($field['key'])->toBe('API_KEY');
    expect($field['value'])->toBe('default-token');
    expect($field['rules'])->toBe('required|string');
});

test('declarativeSetupOptions returns mapped toggles from compose metadata', function () {
    $compose = "
services:
  web:
    image: nginx
x-coolify:
  setup:
    enable_worker:
      key: ENABLE_WORKER
      label: 'Enable Worker Service'
      default: true
      description: 'Check this to enable the background worker.'
    ";

    $service = new Service();
    $service->docker_compose_raw = $compose;

    $options = $service->declarativeSetupOptions();

    expect($options)->toBeInstanceOf(Collection::class);
    expect($options->count())->toBe(1);
    
    $option = $options->first();
    expect($option['label'])->toBe('Enable Worker Service');
    expect($option['key'])->toBe('ENABLE_WORKER');
    expect($option['value'])->toBeTrue();
    expect($option['description'])->toBe('Check this to enable the background worker.');
});

test('declarativeScripts returns scripts from compose metadata', function () {
    $compose = "
services:
  web:
    image: nginx
x-coolify:
  scripts:
    init_db:
      service: web
      command: 'php artisan migrate --force'
    ";

    $service = new Service();
    $service->docker_compose_raw = $compose;

    $scripts = $service->declarativeScripts();

    expect($scripts)->toBeInstanceOf(Collection::class);
    expect($scripts->has('init_db'))->toBeTrue();
    expect($scripts->get('init_db')['command'])->toBe('php artisan migrate --force');
});
