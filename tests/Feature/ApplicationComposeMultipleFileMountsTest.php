<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    InstanceSettings::updateOrCreate(['id' => 0]);
});

it('dedupes when both fs_path and mount_path are identical', function () {
    $resourceId = fake()->unique()->numberBetween(10000, 99999);
    $resourceType = Application::class;

    for ($i = 0; $i < 2; $i++) {
        LocalFileVolume::updateOrCreate(
            [
                'fs_path' => '/data/coolify/test/data/shared.html',
                'mount_path' => '/usr/share/nginx/html/index.html',
                'resource_id' => $resourceId,
                'resource_type' => $resourceType,
            ],
            [
                'fs_path' => '/data/coolify/test/data/shared.html',
                'mount_path' => '/usr/share/nginx/html/index.html',
                'is_directory' => false,
                'resource_id' => $resourceId,
                'resource_type' => $resourceType,
            ]
        );
    }

    $count = LocalFileVolume::query()
        ->where('resource_id', $resourceId)
        ->where('resource_type', $resourceType)
        ->where('mount_path', '/usr/share/nginx/html/index.html')
        ->count();

    expect($count)->toBe(1);
});

function makeReadOnlyVolumeFixture(string $compose, string $fsPath, string $mountPath, ?string $appUuid = 'test-app-uuid'): LocalFileVolume
{
    $app = new Application([
        'uuid' => $appUuid,
        'docker_compose_raw' => $compose,
    ]);
    $app->id = 1;

    $vol = new LocalFileVolume([
        'fs_path' => $fsPath,
        'mount_path' => $mountPath,
    ]);
    $vol->resource_type = Application::class;
    $vol->resource_id = $app->id;
    $vol->setRelation('service', $app);

    return $vol;
}

it('isReadOnlyVolume returns true for an Application compose row when its volume is :ro', function () {
    $compose = <<<'YAML'
services:
  web1:
    image: 'nginx:alpine'
    volumes:
      - '/data/coolify/test/data/index1.html:/usr/share/nginx/html/index.html:ro'
  web2:
    image: 'nginx:alpine'
    volumes:
      - '/data/coolify/test/data/index2.html:/usr/share/nginx/html/index.html:ro'
YAML;

    $vol1 = makeReadOnlyVolumeFixture($compose, '/data/coolify/test/data/index1.html', '/usr/share/nginx/html/index.html');
    $vol2 = makeReadOnlyVolumeFixture($compose, '/data/coolify/test/data/index2.html', '/usr/share/nginx/html/index.html');

    expect($vol1->isReadOnlyVolume())->toBeTrue();
    expect($vol2->isReadOnlyVolume())->toBeTrue();
});

it('isReadOnlyVolume returns false when compose volume has no :ro flag', function () {
    $compose = <<<'YAML'
services:
  web1:
    image: 'nginx:alpine'
    volumes:
      - '/data/coolify/test/data/index1.html:/usr/share/nginx/html/index.html'
YAML;

    $vol = makeReadOnlyVolumeFixture($compose, '/data/coolify/test/data/index1.html', '/usr/share/nginx/html/index.html');

    expect($vol->isReadOnlyVolume())->toBeFalse();
});

it('isReadOnlyVolume disambiguates sibling rows with different :ro flags', function () {
    $compose = <<<'YAML'
services:
  web1:
    image: 'nginx:alpine'
    volumes:
      - '/data/coolify/test/data/index1.html:/usr/share/nginx/html/index.html:ro'
  web2:
    image: 'nginx:alpine'
    volumes:
      - '/data/coolify/test/data/index2.html:/usr/share/nginx/html/index.html'
YAML;

    $volRo = makeReadOnlyVolumeFixture($compose, '/data/coolify/test/data/index1.html', '/usr/share/nginx/html/index.html');
    $volRw = makeReadOnlyVolumeFixture($compose, '/data/coolify/test/data/index2.html', '/usr/share/nginx/html/index.html');

    expect($volRo->isReadOnlyVolume())->toBeTrue();
    expect($volRw->isReadOnlyVolume())->toBeFalse();
});

it('isReadOnlyVolume handles long-form read_only: true', function () {
    $compose = <<<'YAML'
services:
  web1:
    image: 'nginx:alpine'
    volumes:
      - type: bind
        source: /data/coolify/test/data/index1.html
        target: /usr/share/nginx/html/index.html
        read_only: true
YAML;

    $vol = makeReadOnlyVolumeFixture($compose, '/data/coolify/test/data/index1.html', '/usr/share/nginx/html/index.html');

    expect($vol->isReadOnlyVolume())->toBeTrue();
});

it('isReadOnlyVolume resolves relative source paths via replaceLocalSource', function () {
    $compose = <<<'YAML'
services:
  web1:
    image: 'nginx:alpine'
    volumes:
      - './data/index1.html:/usr/share/nginx/html/index.html:ro'
YAML;

    $expectedFs = base_configuration_dir().'/applications/test-app-uuid/data/index1.html';
    $vol = makeReadOnlyVolumeFixture($compose, $expectedFs, '/usr/share/nginx/html/index.html');

    expect($vol->isReadOnlyVolume())->toBeTrue();
});

it('isReadOnlyVolume returns false when fs_path does not match any compose source', function () {
    $compose = <<<'YAML'
services:
  web1:
    image: 'nginx:alpine'
    volumes:
      - '/data/coolify/test/data/index1.html:/usr/share/nginx/html/index.html:ro'
YAML;

    $vol = makeReadOnlyVolumeFixture($compose, '/some/other/host/file.html', '/usr/share/nginx/html/index.html');

    expect($vol->isReadOnlyVolume())->toBeFalse();
});

it('updateOrCreate lookup arrays in parsers.php and shared.php include fs_path', function () {
    $parsers = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');
    $shared = file_get_contents(__DIR__.'/../../bootstrap/helpers/shared.php');

    foreach ([$parsers, $shared] as $code) {
        preg_match_all(
            '/LocalFileVolume::updateOrCreate\s*\(\s*(\[[^\]]+\])/m',
            $code,
            $matches
        );

        expect(count($matches[1]))->toBeGreaterThan(0);
        foreach ($matches[1] as $lookup) {
            expect($lookup)->toContain("'fs_path'");
            expect($lookup)->toContain("'mount_path'");
            expect($lookup)->toContain("'resource_id'");
            expect($lookup)->toContain("'resource_type'");
        }
    }
});
