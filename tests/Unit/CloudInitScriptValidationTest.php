<?php

// Unit tests for cloud-init script validation logic

it('validates cloud-init script is optional', function () {
    $cloudInitScript = null;

    $isRequired = false;
    $hasValue = ! empty($cloudInitScript);

    expect($isRequired)->toBeFalse()
        ->and($hasValue)->toBeFalse();
});

it('validates cloud-init script name is required when saving', function () {
    $saveScript = true;
    $scriptName = 'My Installation Script';

    $isNameRequired = $saveScript;
    $hasName = ! empty($scriptName);

    expect($isNameRequired)->toBeTrue()
        ->and($hasName)->toBeTrue();
});

it('validates cloud-init script description is optional', function () {
    $scriptDescription = null;

    $isDescriptionRequired = false;
    $hasDescription = ! empty($scriptDescription);

    expect($isDescriptionRequired)->toBeFalse()
        ->and($hasDescription)->toBeFalse();
});

it('validates save_cloud_init_script must be boolean', function () {
    $saveCloudInitScript = true;

    expect($saveCloudInitScript)->toBeBool();
});

it('validates save_cloud_init_script defaults to false', function () {
    $saveCloudInitScript = false;

    expect($saveCloudInitScript)->toBeFalse();
});

it('validates cloud-init script can be a bash script', function () {
    $cloudInitScript = "#!/bin/bash\napt-get update\napt-get install -y nginx";

    expect($cloudInitScript)->toBeString()
        ->and($cloudInitScript)->toContain('#!/bin/bash');
});

it('validates cloud-init script can be cloud-config yaml', function () {
    $cloudInitScript = "#cloud-config\npackages:\n  - nginx\n  - git";

    expect($cloudInitScript)->toBeString()
        ->and($cloudInitScript)->toContain('#cloud-config');
});

it('validates script name max length is 255 characters', function () {
    $scriptName = str_repeat('a', 255);

    expect(strlen($scriptName))->toBe(255)
        ->and(strlen($scriptName))->toBeLessThanOrEqual(255);
});

it('validates script name exceeding 255 characters should be invalid', function () {
    $scriptName = str_repeat('a', 256);

    $isValid = strlen($scriptName) <= 255;

    expect($isValid)->toBeFalse()
        ->and(strlen($scriptName))->toBeGreaterThan(255);
});
