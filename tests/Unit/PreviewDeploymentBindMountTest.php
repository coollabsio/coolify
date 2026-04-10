<?php

/**
 * Tests for GitHub issue #7802: volume mappings from repo content in Preview Deployments.
 *
 * Behavioral tests for addPreviewDeploymentSuffix and related helper functions.
 *
 * Note: The parser functions (applicationParser, serviceParser) and
 * ApplicationDeploymentJob methods require database-persisted models with
 * relationships (Application->destination->server, etc.), making them
 * unsuitable for unit tests. Integration tests for those paths belong
 * in tests/Feature/.
 */
describe('addPreviewDeploymentSuffix', function () {
    it('appends -pr-N suffix for non-zero pull request id', function () {
        expect(addPreviewDeploymentSuffix('myvolume', 3))->toBe('myvolume-pr-3');
    });

    it('returns name unchanged when pull request id is zero', function () {
        expect(addPreviewDeploymentSuffix('myvolume', 0))->toBe('myvolume');
    });

    it('handles pull request id of 1', function () {
        expect(addPreviewDeploymentSuffix('scripts', 1))->toBe('scripts-pr-1');
    });

    it('handles large pull request ids', function () {
        expect(addPreviewDeploymentSuffix('data', 9999))->toBe('data-pr-9999');
    });

    it('handles names with dots and slashes', function () {
        expect(addPreviewDeploymentSuffix('./scripts', 2))->toBe('./scripts-pr-2');
    });

    it('handles names with existing hyphens', function () {
        expect(addPreviewDeploymentSuffix('my-volume-name', 5))->toBe('my-volume-name-pr-5');
    });

    it('handles empty name with non-zero pr id', function () {
        expect(addPreviewDeploymentSuffix('', 1))->toBe('-pr-1');
    });

    it('handles uuid-prefixed volume names', function () {
        $uuid = 'abc123_my-volume';
        expect(addPreviewDeploymentSuffix($uuid, 7))->toBe('abc123_my-volume-pr-7');
    });

    it('defaults pull_request_id to 0', function () {
        expect(addPreviewDeploymentSuffix('myvolume'))->toBe('myvolume');
    });
});

describe('sourceIsLocal', function () {
    it('detects relative paths starting with dot-slash', function () {
        expect(sourceIsLocal(str('./scripts')))->toBeTrue();
    });

    it('detects absolute paths starting with slash', function () {
        expect(sourceIsLocal(str('/var/data')))->toBeTrue();
    });

    it('detects tilde paths', function () {
        expect(sourceIsLocal(str('~/data')))->toBeTrue();
    });

    it('detects parent directory paths', function () {
        expect(sourceIsLocal(str('../config')))->toBeTrue();
    });

    it('returns false for named volumes', function () {
        expect(sourceIsLocal(str('myvolume')))->toBeFalse();
    });
});

describe('replaceLocalSource', function () {
    it('replaces dot-slash prefix with target path', function () {
        $result = replaceLocalSource(str('./scripts'), str('/app'));
        expect((string) $result)->toBe('/app/scripts');
    });

    it('replaces dot-dot-slash prefix with target path', function () {
        $result = replaceLocalSource(str('../config'), str('/app'));
        expect((string) $result)->toBe('/app./config');
    });

    it('replaces tilde prefix with target path', function () {
        $result = replaceLocalSource(str('~/data'), str('/app'));
        expect((string) $result)->toBe('/app/data');
    });
});

/**
 * Source-code structure tests for parser and deployment job.
 *
 * These verify that key code patterns exist in the parser and deployment job.
 * They are intentionally text-based because the parser/deployment functions
 * require database-persisted models with deep relationships, making behavioral
 * unit tests impractical. Full behavioral coverage should be done via Feature tests.
 */
describe('parser structure: bind mount handling', function () {
    it('checks is_preview_suffix_enabled before applying suffix', function () {
        $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

        $bindBlockStart = strpos($parsersFile, "if (\$type->value() === 'bind')");
        $volumeBlockStart = strpos($parsersFile, "} elseif (\$type->value() === 'volume')");
        $bindBlock = substr($parsersFile, $bindBlockStart, $volumeBlockStart - $bindBlockStart);

        expect($bindBlock)
            ->toContain('$isPreviewSuffixEnabled')
            ->toContain('is_preview_suffix_enabled')
            ->toContain('addPreviewDeploymentSuffix');
    });

    it('applies preview suffix to named volumes', function () {
        $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

        $volumeBlockStart = strpos($parsersFile, "} elseif (\$type->value() === 'volume')");
        $volumeBlock = substr($parsersFile, $volumeBlockStart, 1000);

        expect($volumeBlock)->toContain('addPreviewDeploymentSuffix');
    });
});

describe('parser structure: label generation uuid isolation', function () {
    it('uses labelUuid instead of mutating shared uuid', function () {
        $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

        $labelBlockStart = strpos($parsersFile, '$shouldGenerateLabelsExactly = $resource->destination->server->settings->generate_exact_labels;');
        $labelBlock = substr($parsersFile, $labelBlockStart, 300);

        expect($labelBlock)
            ->toContain('$labelUuid = $resource->uuid')
            ->not->toContain('$uuid = $resource->uuid')
            ->not->toContain('$uuid = "{$resource->uuid}');
    });

    it('uses labelUuid in all proxy label generation calls', function () {
        $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

        $labelBlockStart = strpos($parsersFile, '$shouldGenerateLabelsExactly');
        $labelBlockEnd = strpos($parsersFile, "data_forget(\$service, 'volumes.*.content')");
        $labelBlock = substr($parsersFile, $labelBlockStart, $labelBlockEnd - $labelBlockStart);

        expect($labelBlock)
            ->toContain('uuid: $labelUuid')
            ->not->toContain('uuid: $uuid');
    });
});

describe('deployment job structure: is_preview_suffix_enabled', function () {
    it('checks setting in generate_local_persistent_volumes', function () {
        $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

        $methodStart = strpos($deploymentJobFile, 'function generate_local_persistent_volumes()');
        $methodEnd = strpos($deploymentJobFile, 'function generate_local_persistent_volumes_only_volume_names()');
        $methodBlock = substr($deploymentJobFile, $methodStart, $methodEnd - $methodStart);

        expect($methodBlock)
            ->toContain('is_preview_suffix_enabled')
            ->toContain('$isPreviewSuffixEnabled')
            ->toContain('addPreviewDeploymentSuffix');
    });

    it('checks setting in generate_local_persistent_volumes_only_volume_names', function () {
        $deploymentJobFile = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

        $methodStart = strpos($deploymentJobFile, 'function generate_local_persistent_volumes_only_volume_names()');
        $methodEnd = strpos($deploymentJobFile, 'function generate_healthcheck_commands()');
        $methodBlock = substr($deploymentJobFile, $methodStart, $methodEnd - $methodStart);

        expect($methodBlock)
            ->toContain('is_preview_suffix_enabled')
            ->toContain('$isPreviewSuffixEnabled')
            ->toContain('addPreviewDeploymentSuffix');
    });
});
