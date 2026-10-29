<?php

use App\Actions\ImageScan\ScanImage;
use App\Enums\ImageScanStatus;
use App\Enums\SecurityActionType;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\ImageScan;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandalonePostgresql;
use App\Services\ImageScan\IgnoreMatcher;
use App\Services\ImageScan\SecurityAction;
use App\Services\ImageScan\SecurityActions;
use Illuminate\Database\Eloquent\Model;

/**
 * A relevant finding row (critical, OS package with a package fix) unless overridden.
 *
 * @return array<string, mixed>
 */
function actionFinding(string $id, array $overrides = []): array
{
    return [
        'id' => $id, 'aliases' => [], 'severity' => 'critical', 'package' => 'openssl', 'version' => '3.0.13',
        'type' => 'apk', 'artifactId' => 'a-'.$id, 'fixedIn' => '3.0.15', 'fixState' => 'fixed', 'kev' => false,
        'namespace' => 'alpine:distro:alpine:3.20', ...$overrides,
    ];
}

/**
 * @template T of Model
 *
 * @param  T  $model
 * @return T
 */
function actionOwner(Model $model, int $id = 5): Model
{
    $model->id = $id;
    $model->syncOriginal();

    return $model;
}

function actionApp(string $buildPack, int $id = 5): Application
{
    return actionOwner(new Application(['build_pack' => $buildPack]), $id);
}

/**
 * @param  list<array<string, mixed>>  $findings
 * @param  array<string, mixed>  $attributes
 */
function actionScan(Model $owner, array $findings, array $attributes = [], ?Server $server = null): ImageScan
{
    $scan = new ImageScan([
        'status' => ImageScanStatus::Completed,
        'image_ref' => 'postgres:15.0-alpine',
        'builds_image' => false,
        'findings' => $findings,
        'candidates' => null,
        ...$attributes,
    ]);
    $scan->setRelation('resource', $owner);
    $scan->setRelation('server', $server);

    return $scan;
}

/**
 * @param  list<string>  $fixedIds
 * @return array<string, mixed>
 */
function actionEntry(string $ref, string $kind, array $fixedIds, bool $better = true, ?array $eol = null): array
{
    return ['ref' => $ref, 'digest' => 'sha256:'.md5($ref), 'kind' => $kind, 'better' => $better, 'fixed_ids' => $fixedIds, 'fixes' => [], 'left' => [], 'eol' => $eol];
}

/**
 * @param  list<array<string, mixed>>  $entries
 * @return array<string, mixed>
 */
function actionCandidates(array $entries = [], ?array $base = null): array
{
    return ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'entries' => $entries, ...($base === null ? [] : ['base' => $base])];
}

/**
 * @param  list<string>  $attributableIds
 * @param  list<array<string, mixed>>  $entries
 * @return array<string, mixed>
 */
function actionBase(array $attributableIds, array $entries, string $image = 'node:20.1-alpine'): array
{
    return ['image' => $image, 'status' => 'ok', 'attributable' => count($attributableIds), 'attributable_ids' => $attributableIds, 'current_digest' => 'sha256:'.md5($image), 'entries' => $entries];
}

/**
 * @return array{actions: list<SecurityAction>, byFinding: array<string, SecurityAction>, eol: ?SecurityAction}
 */
function actionsFor(ImageScan $scan): array
{
    return SecurityActions::forScan($scan, IgnoreMatcher::fromIgnores(collect()));
}

/**
 * A Dockerfile app scan. The default base check is for the current base image and found no finding of the base.
 */
function dockerfileScan(array $findings, ?array $base = [], array $attributes = []): ImageScan
{
    $base = $base === [] ? actionBase([], []) : $base;

    return actionScan(actionApp('dockerfile'), $findings, [
        'image_ref' => 'abc123:latest',
        'builds_image' => true,
        'base_image_ref' => 'node:20.1-alpine',
        'candidates' => $base === null ? null : ['status' => 'skipped', 'checked_at' => now()->toIso8601String(), 'entries' => [], 'base' => $base],
        ...$attributes,
    ]);
}

function composeApp(bool $raw = false): Application
{
    $app = actionApp('dockercompose');
    $app->uuid = 'k8w2c9';
    $app->compose_parsing_version = '5';
    $app->setRelation('settings', new ApplicationSetting(['is_raw_compose_deployment_enabled' => $raw]));
    $app->docker_compose = "services:\n  web:\n    build: .\n    image: 'k8w2c9_web:4f1c2d3'\n  worker:\n    build:\n      context: ./worker\n    image: 'ghcr.io/acme/worker:2.0'\n  db:\n    image: 'postgres:16'\n";
    $app->syncOriginal();

    return $app;
}

function ownDockerImageScan(array $findings, ?array $candidates = null): ImageScan
{
    return actionScan(actionApp('dockerimage'), $findings, ['image_ref' => 'acme/api:1.4', 'builds_image' => true, 'candidates' => $candidates]);
}

describe('third-party images', function () {
    it('updates to a better line candidate that no longer has the finding', function () {
        $scan = actionScan(actionOwner(new StandalonePostgresql), [actionFinding('CVE-2025-1094', ['kev' => true])], [
            'candidates' => actionCandidates([actionEntry('postgres:15.15-alpine', 'line', ['CVE-2025-1094'])]),
        ]);

        $result = actionsFor($scan);
        $action = $result['byFinding']['CVE-2025-1094'];

        expect($result['actions'])->toHaveCount(1)
            ->and($action->type)->toBe(SecurityActionType::UpdateImage)
            ->and($action->sentence)->toBe('Update to 15.15-alpine.')
            ->and($action->target)->toBe('15.15-alpine')
            ->and($action->ref)->toBe('postgres:15.15-alpine')
            ->and($action->image)->toBe('postgres:15.0-alpine')
            ->and($action->fixedIds)->toBe(['CVE-2025-1094'])
            ->and($action->button)->toBeTrue()
            ->and($action->copy)->toBeNull();
    });

    it('matches the fixed IDs of a candidate by alias and in any case', function () {
        $scan = actionScan(actionOwner(new StandalonePostgresql), [actionFinding('GHSA-aaaa-bbbb-cccc', ['aliases' => ['CVE-2025-1094']])], [
            'candidates' => actionCandidates([actionEntry('postgres:15.15-alpine', 'line', ['cve-2025-1094'])]),
        ]);

        expect(actionsFor($scan)['byFinding']['GHSA-aaaa-bbbb-cccc']->type)->toBe(SecurityActionType::UpdateImage);
    });

    it('pulls the newest build when only a better same_tag candidate fixes the finding', function () {
        $scan = actionScan(actionOwner(new StandalonePostgresql), [actionFinding('CVE-1'), actionFinding('CVE-2')], [
            'candidates' => actionCandidates([
                actionEntry('postgres:15.15-alpine', 'line', ['CVE-1']),
                actionEntry('postgres:15.0-alpine', 'same_tag', ['CVE-1', 'CVE-2']),
            ]),
        ]);

        $result = actionsFor($scan);

        expect($result['byFinding']['CVE-1']->type)->toBe(SecurityActionType::UpdateImage)
            ->and($result['byFinding']['CVE-2']->type)->toBe(SecurityActionType::PullLatest)
            ->and($result['byFinding']['CVE-2']->sentence)->toBe('A newer build of this image fixes it. Pull it and restart.')
            ->and($result['byFinding']['CVE-2']->button)->toBeTrue()
            ->and($result['actions'])->toHaveCount(2);
    });

    it('never offers a major version or a candidate that is not better', function () {
        $scan = actionScan(actionOwner(new StandalonePostgresql), [actionFinding('CVE-1')], [
            'candidates' => actionCandidates([
                actionEntry('postgres:16.4-alpine', 'major', ['CVE-1']),
                actionEntry('postgres:15.15-alpine', 'line', ['CVE-1'], better: false),
            ]),
        ]);

        expect(actionsFor($scan)['actions'])->toBe([]);
    });

    it('gives no action to a finding no candidate fixes, nor to a non-relevant finding', function () {
        $scan = actionScan(actionOwner(new StandalonePostgresql), [
            actionFinding('CVE-1'),
            actionFinding('CVE-2', ['severity' => 'medium']),
        ], ['candidates' => actionCandidates([actionEntry('postgres:15.15-alpine', 'line', ['CVE-2'])])]);

        $result = actionsFor($scan);

        expect($result['byFinding'])->toBe([])->and($result['actions'])->toBe([]);
    });

    it('gives a third-party image no package action', function () {
        $scan = actionScan(actionOwner(new StandalonePostgresql), [actionFinding('CVE-1', ['type' => 'go-module', 'package' => 'stdlib'])]);

        expect(actionsFor($scan)['actions'])->toBe([]);
    });

    it('updates an image that leaves an end-of-life OS', function () {
        $scan = actionScan(actionOwner(new StandalonePostgresql), [], [
            'summary' => ['eol_distro' => ['name' => 'alpine', 'version' => '3.16']],
            'candidates' => actionCandidates([actionEntry('postgres:15.15-alpine', 'line', [])]),
        ]);

        $result = actionsFor($scan);

        expect($result['eol']?->type)->toBe(SecurityActionType::UpdateImage)
            ->and($result['actions'])->toHaveCount(1);
    });
});

describe('buttons', function () {
    it('gives Coolify resources (id 0) no button and the Coolify update text', function () {
        $scan = actionScan(actionOwner(new StandalonePostgresql, 0), [actionFinding('CVE-1')], [
            'candidates' => actionCandidates([actionEntry('postgres:15.15-alpine', 'line', ['CVE-1'])]),
        ]);

        $action = actionsFor($scan)['byFinding']['CVE-1'];

        expect($action->button)->toBeFalse()
            ->and($action->sentence)->toBe('Update Coolify to get a newer version.');
    });

    it('gives the images of a Coolify service (id 0) no button', function () {
        $service = actionOwner(new Service, 0);
        $application = actionOwner(new ServiceApplication(['image' => 'postgres:15.0-alpine']), 3);
        $application->setRelation('service', $service);
        $scan = actionScan($application, [actionFinding('CVE-1')], [
            'candidates' => actionCandidates([actionEntry('postgres:15.15-alpine', 'line', ['CVE-1'])]),
        ]);

        expect(actionsFor($scan)['byFinding']['CVE-1']->button)->toBeFalse();
    });

    it('gives no button on a transferred-away server', function () {
        $server = new Server(['server_metadata' => ['transfer' => ['status' => 'transferred']]]);
        $scan = actionScan(actionOwner(new StandalonePostgresql), [actionFinding('CVE-1')], [
            'candidates' => actionCandidates([actionEntry('postgres:15.15-alpine', 'line', ['CVE-1'])]),
        ], $server);

        expect(actionsFor($scan)['byFinding']['CVE-1']->button)->toBeFalse();
    });

    it('gives no button when the compose file sets the image in a way Coolify cannot change', function () {
        $service = actionOwner(new Service, 9);
        $application = actionOwner(new ServiceApplication(['image' => '${PG_REPO}:${PG_VERSION}']), 3);
        $application->setRelation('service', $service);
        $scan = actionScan($application, [actionFinding('CVE-1')], [
            'candidates' => actionCandidates([actionEntry('postgres:15.15-alpine', 'line', ['CVE-1'])]),
        ]);

        $action = actionsFor($scan)['byFinding']['CVE-1'];

        expect($action->type)->toBe(SecurityActionType::UpdateImage)->and($action->button)->toBeFalse();
    });
});

describe('own Dockerfile images', function () {
    it('changes the base image for a finding from the base that a better base candidate fixes', function () {
        $scan = dockerfileScan([actionFinding('CVE-1', ['kev' => true])], actionBase(['CVE-1'], [actionEntry('node:20.18-alpine', 'line', ['CVE-1'])]));

        $action = actionsFor($scan)['byFinding']['CVE-1'];

        expect($action->type)->toBe(SecurityActionType::ChangeBase)
            ->and($action->sentence)->toBe('Change the base image to node:20.18-alpine in your Dockerfile, then redeploy.')
            ->and($action->target)->toBe('node:20.18-alpine')
            ->and($action->copy)->toBe('FROM node:20.18-alpine')
            ->and($action->button)->toBeFalse();
    });

    it('gives no package or rebuild action while the base check does not know the base findings', function (?array $base) {
        $scan = dockerfileScan([
            actionFinding('CVE-1', ['type' => 'npm', 'package' => 'cross-spawn', 'version' => '7.0.3', 'fixedIn' => '7.0.5']),
            actionFinding('CVE-2'),
        ], $base);

        expect(actionsFor($scan)['actions'])->toBe([]);
    })->with([
        'not run yet' => [null],
        'auth required' => [['image' => 'node:20.1-alpine', 'status' => 'auth_required', 'attributable' => null, 'attributable_ids' => [], 'entries' => []]],
        'failed' => [['image' => 'node:20.1-alpine', 'status' => 'failed', 'attributable' => null, 'attributable_ids' => [], 'entries' => []]],
        'for another base image' => [actionBase([], [], 'node:18.1-alpine')],
    ]);

    it('acts on the findings when the base check knows the base has none of them', function () {
        $scan = dockerfileScan([
            actionFinding('CVE-1', ['type' => 'npm', 'package' => 'cross-spawn', 'version' => '7.0.3', 'fixedIn' => '7.0.5']),
            actionFinding('CVE-2'),
        ], ['image' => 'node:20.1-alpine', 'status' => 'no_better', 'attributable' => 0, 'attributable_ids' => [], 'entries' => []]);

        $result = actionsFor($scan);

        expect($result['byFinding']['CVE-1']->type)->toBe(SecurityActionType::UpgradePackage)
            ->and($result['byFinding']['CVE-2']->type)->toBe(SecurityActionType::Rebuild);
    });

    it('acts on the findings of a Dockerfile app without a known base image', function () {
        $scan = dockerfileScan([actionFinding('CVE-1')], null, ['base_image_ref' => null]);

        expect(actionsFor($scan)['byFinding']['CVE-1']->type)->toBe(SecurityActionType::Rebuild);
    });

    it('rebuilds without cache for a fixed OS package that is not in the current base', function () {
        $scan = dockerfileScan([actionFinding('CVE-1'), actionFinding('CVE-2', ['package' => 'zlib'])], actionBase([], []));

        $result = actionsFor($scan);
        $action = $result['byFinding']['CVE-1'];

        expect($result['actions'])->toHaveCount(1)
            ->and($result['byFinding']['CVE-2'])->toBe($action)
            ->and($action->type)->toBe(SecurityActionType::Rebuild)
            ->and($action->sentence)->toBe('Rebuild without cache to get the fixed system packages.')
            ->and($action->fixedIds)->toBe(['CVE-1', 'CVE-2'])
            ->and($action->button)->toBeTrue();
    });

    it('does not rebuild for an OS package without a package fix', function () {
        $scan = dockerfileScan([actionFinding('CVE-1', ['fixState' => 'not-fixed', 'fixedIn' => null])]);

        expect(actionsFor($scan)['actions'])->toBe([]);
    });

    it('gives an OS package from the base without a better base candidate no action', function () {
        $scan = dockerfileScan([actionFinding('CVE-1')], actionBase(['CVE-1'], []));

        expect(actionsFor($scan)['actions'])->toBe([]);
    });

    it('upgrades a language package that does not come from the base, one action per package', function () {
        $scan = dockerfileScan([
            actionFinding('CVE-1', ['type' => 'npm', 'package' => 'express', 'version' => '4.17.1', 'fixedIn' => '4.19.2']),
            actionFinding('CVE-2', ['type' => 'npm', 'package' => 'express', 'version' => '4.17.1', 'fixedIn' => '4.20.0']),
            actionFinding('CVE-3', ['type' => 'npm', 'package' => 'express', 'version' => '4.17.1', 'fixedIn' => '4.18.0']),
            actionFinding('CVE-4', ['type' => 'python', 'package' => 'django', 'version' => '4.2.0', 'fixedIn' => '4.2.16']),
        ]);

        $result = actionsFor($scan);
        $express = $result['byFinding']['CVE-1'];

        expect($result['actions'])->toHaveCount(2)
            ->and($result['byFinding']['CVE-2'])->toBe($express)
            ->and($result['byFinding']['CVE-3'])->toBe($express)
            ->and($express->type)->toBe(SecurityActionType::UpgradePackage)
            ->and($express->sentence)->toBe('Upgrade express to 4.20.0 or later, then redeploy.')
            ->and($express->target)->toBe('express')
            ->and($express->copy)->toBe('express 4.20.0')
            ->and($express->button)->toBeFalse()
            ->and($express->fixedIds)->toBe(['CVE-1', 'CVE-2', 'CVE-3'])
            ->and($result['byFinding']['CVE-4']->sentence)->toBe('Upgrade django to 4.2.16 or later, then redeploy.');
    });

    it('notes a major version change', function () {
        $scan = dockerfileScan([actionFinding('CVE-1', ['type' => 'go-module', 'package' => 'golang.org/x/net', 'version' => 'v0.17.0', 'fixedIn' => 'v1.0.1'])]);

        expect(actionsFor($scan)['byFinding']['CVE-1']->sentence)
            ->toBe('Upgrade golang.org/x/net to v1.0.1 or later, then redeploy. This is a major version change. Check for breaking changes.');
    });

    it('keeps the first fixed version when versions cannot be compared', function () {
        $scan = dockerfileScan([
            actionFinding('CVE-1', ['type' => 'npm', 'package' => 'lib', 'version' => '1.0.0', 'fixedIn' => '1.2.0']),
            actionFinding('CVE-2', ['type' => 'npm', 'package' => 'lib', 'version' => '1.0.0', 'fixedIn' => 'next-release']),
        ]);

        expect(actionsFor($scan)['byFinding']['CVE-1']->target)->toBe('lib')
            ->and(actionsFor($scan)['byFinding']['CVE-2']->copy)->toBe('lib 1.2.0');
    });

    it('prefers a fixed version to a value that is not one', function () {
        $scan = dockerfileScan([
            actionFinding('CVE-1', ['type' => 'npm', 'package' => 'lib', 'version' => '1.0.0', 'fixedIn' => 'next-release']),
            actionFinding('CVE-2', ['type' => 'npm', 'package' => 'lib', 'version' => '1.0.0', 'fixedIn' => '1.2.0']),
        ]);

        expect(actionsFor($scan)['byFinding']['CVE-1']->copy)->toBe('lib 1.2.0');
    });

    it('never upgrades a binary or a language package from the base', function () {
        $scan = dockerfileScan([
            actionFinding('CVE-1', ['type' => 'binary', 'package' => 'node', 'version' => '20.1.0', 'fixedIn' => '20.1.1']),
            actionFinding('CVE-2', ['type' => 'npm', 'package' => 'npm', 'version' => '9.6.0', 'fixedIn' => '9.6.7']),
        ], actionBase(['CVE-1', 'CVE-2'], []));

        expect(actionsFor($scan)['actions'])->toBe([]);
    });

    it('changes the base for a binary from the base that the base candidate fixes', function () {
        $scan = dockerfileScan([
            actionFinding('CVE-1', ['type' => 'binary', 'package' => 'node', 'version' => '20.1.0', 'fixedIn' => '20.1.1']),
        ], actionBase(['CVE-1'], [actionEntry('node:20.18-alpine', 'line', ['CVE-1'])]));

        expect(actionsFor($scan)['byFinding']['CVE-1']->type)->toBe(SecurityActionType::ChangeBase);
    });

    it('gives a Dockerfile image no image update', function () {
        $scan = dockerfileScan([actionFinding('CVE-1', ['fixState' => 'not-fixed', 'fixedIn' => null])], null, [
            'candidates' => actionCandidates([actionEntry('abc123:2', 'line', ['CVE-1'])]),
        ]);

        expect(actionsFor($scan)['actions'])->toBe([]);
    });
});

describe('own Docker image apps', function () {
    it('updates to a better candidate first', function () {
        $scan = ownDockerImageScan([actionFinding('CVE-1')], actionCandidates([actionEntry('acme/api:1.5', 'line', ['CVE-1'])]));

        expect(actionsFor($scan)['byFinding']['CVE-1']->sentence)->toBe('Update to 1.5.');
    });

    it('rebuilds in CI for an OS package with a package fix', function () {
        $scan = ownDockerImageScan([actionFinding('CVE-1')]);

        $action = actionsFor($scan)['byFinding']['CVE-1'];

        expect($action->type)->toBe(SecurityActionType::RebuildInCi)
            ->and($action->sentence)->toBe('Rebuild the image in your CI, then redeploy.')
            ->and($action->button)->toBeFalse();
    });

    it('upgrades a language package', function () {
        $scan = ownDockerImageScan([actionFinding('CVE-1', ['type' => 'gem', 'package' => 'rack', 'version' => '2.2.3', 'fixedIn' => '2.2.8'])]);

        expect(actionsFor($scan)['byFinding']['CVE-1']->type)->toBe(SecurityActionType::UpgradePackage);
    });
});

describe('compose builds and builder images', function () {
    it('gives the OS packages of a compose build no action, but upgrades its language packages', function () {
        $app = composeApp();
        $imageRef = 'k8w2c9_web:4f1c2d3';
        $scan = actionScan($app, [
            actionFinding('CVE-1'),
            actionFinding('CVE-2', ['type' => 'php-composer', 'package' => 'guzzlehttp/psr7', 'version' => '2.4.0', 'fixedIn' => '2.4.5']),
        ], ['image_ref' => $imageRef, 'builds_image' => ScanImage::buildsImage($app, $imageRef)]);

        $result = actionsFor($scan);

        expect(array_keys($result['byFinding']))->toBe(['CVE-2'])
            ->and($result['byFinding']['CVE-2']->type)->toBe(SecurityActionType::UpgradePackage);
    });

    it('treats a pulled image of a compose app as third-party, with a button that keeps the new image', function () {
        $app = composeApp();
        $scan = actionScan($app, [
            actionFinding('CVE-1'),
            actionFinding('CVE-2', ['type' => 'go-module', 'package' => 'stdlib', 'version' => 'go1.22.1', 'fixedIn' => '1.22.5']),
        ], [
            'image_ref' => 'postgres:16', 'builds_image' => ScanImage::buildsImage($app, 'postgres:16'),
            'candidates' => actionCandidates([actionEntry('postgres:16.4', 'line', ['CVE-1'])]),
        ]);

        $result = actionsFor($scan);

        expect($scan->builds_image)->toBeFalse()
            ->and(array_keys($result['byFinding']))->toBe(['CVE-1'])
            ->and($result['byFinding']['CVE-1']->type)->toBe(SecurityActionType::UpdateImage)
            ->and($result['byFinding']['CVE-1']->sentence)->toBe('Update to 16.4.')
            ->and($result['byFinding']['CVE-1']->target)->toBe('16.4')
            ->and($result['byFinding']['CVE-1']->button)->toBeTrue();
    });

    it('tells a compose app that deploys its file as written to change the version in the file', function () {
        $app = composeApp(raw: true);
        $scan = actionScan($app, [actionFinding('CVE-1')], [
            'image_ref' => 'postgres:16', 'builds_image' => ScanImage::buildsImage($app, 'postgres:16'),
            'candidates' => actionCandidates([actionEntry('postgres:16.4', 'line', ['CVE-1'])]),
        ]);

        $action = actionsFor($scan)['byFinding']['CVE-1'];

        expect($action->sentence)->toBe('Change the version to 16.4 in the compose file of this app, then redeploy.')
            ->and($action->button)->toBeFalse();
    });

    it('pulls the scanned build and redeploys a compose app for a newer build of a pulled image', function () {
        $app = composeApp();
        $scan = actionScan($app, [actionFinding('CVE-1')], [
            'image_ref' => 'postgres:16', 'builds_image' => ScanImage::buildsImage($app, 'postgres:16'),
            'candidates' => actionCandidates([actionEntry('postgres:16', 'same_tag', ['CVE-1'])]),
        ]);

        $action = actionsFor($scan)['byFinding']['CVE-1'];

        expect($action->type)->toBe(SecurityActionType::PullLatest)
            ->and($action->sentence)->toBe('A newer build of this image fixes it. Pull it and redeploy.')
            ->and($action->target)->toBe('16')
            ->and($action->button)->toBeTrue();
    });

    it('decides which compose images Coolify builds', function (string $imageRef, bool $builds) {
        expect(ScanImage::buildsImage(composeApp(), $imageRef))->toBe($builds);
    })->with([
        'built service without an image' => ['k8w2c9_web:4f1c2d3', true],
        'built preview' => ['k8w2c9_web:pr-4', true],
        'built service with an image' => ['ghcr.io/acme/worker:2.0', true],
        'built service with an image, other tag' => ['ghcr.io/acme/worker:2.1', true],
        'pulled image' => ['postgres:16', false],
        'pulled image of another app' => ['other_web:4f1c2d3', false],
    ]);

    it('gives the OS packages of a builder image no action', function (string $buildPack) {
        $scan = actionScan(actionApp($buildPack), [actionFinding('CVE-1', ['type' => 'deb'])], ['image_ref' => 'abc123:latest', 'builds_image' => true]);

        expect(actionsFor($scan)['actions'])->toBe([]);
    })->with(['nixpacks', 'railpack', 'static']);

    it('upgrades the language packages of a builder image', function () {
        $scan = actionScan(actionApp('nixpacks'), [actionFinding('CVE-1', ['type' => 'npm', 'package' => 'express', 'version' => '4.17.1', 'fixedIn' => '4.19.2'])], ['image_ref' => 'abc123:latest', 'builds_image' => true]);

        expect(actionsFor($scan)['byFinding']['CVE-1']->type)->toBe(SecurityActionType::UpgradePackage);
    });

    it('has the builder text for findings without action', function () {
        expect(SecurityActions::BUILDER_TEXT)->toBe('A fix exists, but it reaches this app only with a builder update in a Coolify release. To choose the base image yourself, use the Dockerfile build pack.');
    });
});
