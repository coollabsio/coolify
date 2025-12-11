<?php

beforeEach(function () {
    Mockery::close();
});

afterEach(function () {
    Mockery::close();
});

it('categorizes images correctly into PR and regular images', function () {
    // Test the image categorization logic
    // Build images (*-build) are excluded from retention and handled by docker image prune
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'abc123', 'created_at' => '2024-01-01', 'image_ref' => 'app-uuid:abc123'],
        ['repository' => 'app-uuid', 'tag' => 'def456', 'created_at' => '2024-01-02', 'image_ref' => 'app-uuid:def456'],
        ['repository' => 'app-uuid', 'tag' => 'pr-123', 'created_at' => '2024-01-03', 'image_ref' => 'app-uuid:pr-123'],
        ['repository' => 'app-uuid', 'tag' => 'pr-456', 'created_at' => '2024-01-04', 'image_ref' => 'app-uuid:pr-456'],
        ['repository' => 'app-uuid', 'tag' => 'abc123-build', 'created_at' => '2024-01-05', 'image_ref' => 'app-uuid:abc123-build'],
        ['repository' => 'app-uuid', 'tag' => 'def456-build', 'created_at' => '2024-01-06', 'image_ref' => 'app-uuid:def456-build'],
    ]);

    // PR images (tags starting with 'pr-')
    $prImages = $images->filter(fn ($image) => str_starts_with($image['tag'], 'pr-'));
    expect($prImages)->toHaveCount(2);
    expect($prImages->pluck('tag')->toArray())->toContain('pr-123', 'pr-456');

    // Regular images (neither PR nor build) - these are subject to retention policy
    $regularImages = $images->filter(fn ($image) => ! str_starts_with($image['tag'], 'pr-') && ! str_ends_with($image['tag'], '-build'));
    expect($regularImages)->toHaveCount(2);
    expect($regularImages->pluck('tag')->toArray())->toContain('abc123', 'def456');
});

it('filters out currently running image from deletion candidates', function () {
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'abc123', 'created_at' => '2024-01-01', 'image_ref' => 'app-uuid:abc123'],
        ['repository' => 'app-uuid', 'tag' => 'def456', 'created_at' => '2024-01-02', 'image_ref' => 'app-uuid:def456'],
        ['repository' => 'app-uuid', 'tag' => 'ghi789', 'created_at' => '2024-01-03', 'image_ref' => 'app-uuid:ghi789'],
    ]);

    $currentTag = 'def456';

    $deletionCandidates = $images->filter(fn ($image) => $image['tag'] !== $currentTag);

    expect($deletionCandidates)->toHaveCount(2);
    expect($deletionCandidates->pluck('tag')->toArray())->not->toContain('def456');
    expect($deletionCandidates->pluck('tag')->toArray())->toContain('abc123', 'ghi789');
});

it('keeps the correct number of images based on docker_images_to_keep setting', function () {
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid:commit1'],
        ['repository' => 'app-uuid', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid:commit2'],
        ['repository' => 'app-uuid', 'tag' => 'commit3', 'created_at' => '2024-01-03 10:00:00', 'image_ref' => 'app-uuid:commit3'],
        ['repository' => 'app-uuid', 'tag' => 'commit4', 'created_at' => '2024-01-04 10:00:00', 'image_ref' => 'app-uuid:commit4'],
        ['repository' => 'app-uuid', 'tag' => 'commit5', 'created_at' => '2024-01-05 10:00:00', 'image_ref' => 'app-uuid:commit5'],
    ]);

    $currentTag = 'commit5';
    $imagesToKeep = 2;

    // Filter out current, sort by date descending, keep N
    $sortedImages = $images
        ->filter(fn ($image) => $image['tag'] !== $currentTag)
        ->sortByDesc('created_at')
        ->values();

    $imagesToDelete = $sortedImages->skip($imagesToKeep);

    // Should delete commit1, commit2 (oldest 2 after keeping 2 newest: commit4, commit3)
    expect($imagesToDelete)->toHaveCount(2);
    expect($imagesToDelete->pluck('tag')->toArray())->toContain('commit1', 'commit2');
});

it('deletes all images when docker_images_to_keep is 0', function () {
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid:commit1'],
        ['repository' => 'app-uuid', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid:commit2'],
        ['repository' => 'app-uuid', 'tag' => 'commit3', 'created_at' => '2024-01-03 10:00:00', 'image_ref' => 'app-uuid:commit3'],
    ]);

    $currentTag = 'commit3';
    $imagesToKeep = 0;

    $sortedImages = $images
        ->filter(fn ($image) => $image['tag'] !== $currentTag)
        ->sortByDesc('created_at')
        ->values();

    $imagesToDelete = $sortedImages->skip($imagesToKeep);

    // Should delete all images except the currently running one
    expect($imagesToDelete)->toHaveCount(2);
    expect($imagesToDelete->pluck('tag')->toArray())->toContain('commit1', 'commit2');
});

it('does not delete any images when there are fewer than images_to_keep', function () {
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid:commit1'],
        ['repository' => 'app-uuid', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid:commit2'],
    ]);

    $currentTag = 'commit2';
    $imagesToKeep = 5;

    $sortedImages = $images
        ->filter(fn ($image) => $image['tag'] !== $currentTag)
        ->sortByDesc('created_at')
        ->values();

    $imagesToDelete = $sortedImages->skip($imagesToKeep);

    // Should not delete anything - we have fewer images than the keep limit
    expect($imagesToDelete)->toHaveCount(0);
});

it('handles images with custom registry names', function () {
    // Test that the logic works regardless of repository name format
    $images = collect([
        ['repository' => 'registry.example.com/my-app', 'tag' => 'v1.0.0', 'created_at' => '2024-01-01', 'image_ref' => 'registry.example.com/my-app:v1.0.0'],
        ['repository' => 'registry.example.com/my-app', 'tag' => 'v1.1.0', 'created_at' => '2024-01-02', 'image_ref' => 'registry.example.com/my-app:v1.1.0'],
        ['repository' => 'registry.example.com/my-app', 'tag' => 'pr-99', 'created_at' => '2024-01-03', 'image_ref' => 'registry.example.com/my-app:pr-99'],
    ]);

    $prImages = $images->filter(fn ($image) => str_starts_with($image['tag'], 'pr-'));
    $regularImages = $images->filter(fn ($image) => ! str_starts_with($image['tag'], 'pr-') && ! str_ends_with($image['tag'], '-build'));

    expect($prImages)->toHaveCount(1);
    expect($regularImages)->toHaveCount(2);
});

it('correctly identifies PR build images as PR images', function () {
    // PR build images have tags like 'pr-123-build'
    // They are identified as PR images (start with 'pr-') and will be deleted
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'pr-123', 'created_at' => '2024-01-01', 'image_ref' => 'app-uuid:pr-123'],
        ['repository' => 'app-uuid', 'tag' => 'pr-123-build', 'created_at' => '2024-01-02', 'image_ref' => 'app-uuid:pr-123-build'],
    ]);

    // PR images include both pr-123 and pr-123-build (both start with 'pr-')
    $prImages = $images->filter(fn ($image) => str_starts_with($image['tag'], 'pr-'));

    expect($prImages)->toHaveCount(2);
});

it('defaults to keeping 2 images when setting is null', function () {
    $defaultValue = 2;

    // Simulate the null coalescing behavior
    $dockerImagesToKeep = null ?? $defaultValue;

    expect($dockerImagesToKeep)->toBe(2);
});

it('does not delete images when count equals images_to_keep', function () {
    // Scenario: User has 3 images, 1 is running, 2 remain, keep limit is 2
    // Expected: No images should be deleted
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid:commit1'],
        ['repository' => 'app-uuid', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid:commit2'],
        ['repository' => 'app-uuid', 'tag' => 'commit3', 'created_at' => '2024-01-03 10:00:00', 'image_ref' => 'app-uuid:commit3'],
    ]);

    $currentTag = 'commit3'; // This is running
    $imagesToKeep = 2;

    $sortedImages = $images
        ->filter(fn ($image) => $image['tag'] !== $currentTag)
        ->sortByDesc('created_at')
        ->values();

    // After filtering out running image, we have 2 images
    expect($sortedImages)->toHaveCount(2);

    $imagesToDelete = $sortedImages->skip($imagesToKeep);

    // Skip 2, leaving 0 to delete
    expect($imagesToDelete)->toHaveCount(0);
});

it('handles scenario where no container is running', function () {
    // Scenario: 2 images exist, none running, keep limit is 2
    // Expected: No images should be deleted (keep all 2)
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid:commit1'],
        ['repository' => 'app-uuid', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid:commit2'],
    ]);

    $currentTag = ''; // No container running, empty tag
    $imagesToKeep = 2;

    $sortedImages = $images
        ->filter(fn ($image) => $image['tag'] !== $currentTag)
        ->sortByDesc('created_at')
        ->values();

    // All images remain since none match the empty current tag
    expect($sortedImages)->toHaveCount(2);

    $imagesToDelete = $sortedImages->skip($imagesToKeep);

    // Skip 2, leaving 0 to delete
    expect($imagesToDelete)->toHaveCount(0);
});

it('handles Docker Compose service images with uuid_servicename pattern', function () {
    // Docker Compose with build: directive creates images like uuid_servicename:tag
    $images = collect([
        ['repository' => 'app-uuid_web', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid_web:commit1'],
        ['repository' => 'app-uuid_web', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid_web:commit2'],
        ['repository' => 'app-uuid_worker', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid_worker:commit1'],
        ['repository' => 'app-uuid_worker', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid_worker:commit2'],
    ]);

    // All images should be categorized as regular images (not PR, not build)
    $prImages = $images->filter(fn ($image) => str_starts_with($image['tag'], 'pr-'));
    $regularImages = $images->filter(fn ($image) => ! str_starts_with($image['tag'], 'pr-') && ! str_ends_with($image['tag'], '-build'));

    expect($prImages)->toHaveCount(0);
    expect($regularImages)->toHaveCount(4);
});

it('correctly excludes Docker Compose images from general prune', function () {
    // Test the grep pattern that excludes application images
    // Pattern should match both uuid:tag and uuid_servicename:tag
    $appUuid = 'abc123def456';
    $excludePattern = preg_quote($appUuid, '/');

    // Images that should be EXCLUDED (protected)
    $protectedImages = [
        "{$appUuid}:commit1",           // Standard app image
        "{$appUuid}_web:commit1",       // Docker Compose service
        "{$appUuid}_worker:commit2",    // Docker Compose service
    ];

    // Images that should be INCLUDED (deleted)
    $deletableImages = [
        'other-app:latest',
        'nginx:alpine',
        'postgres:15',
    ];

    // Test the regex pattern used in buildImagePruneCommand
    $pattern = "/^({$excludePattern})[_:].+/";

    foreach ($protectedImages as $image) {
        expect(preg_match($pattern, $image))->toBe(1, "Image {$image} should be protected");
    }

    foreach ($deletableImages as $image) {
        expect(preg_match($pattern, $image))->toBe(0, "Image {$image} should be deletable");
    }
});

it('excludes current version of Coolify infrastructure images from any registry', function () {
    // Test the regex pattern used to protect the current version of infrastructure images
    // regardless of which registry they come from (ghcr.io, docker.io, or no prefix)
    $helperVersion = '1.0.12';
    $realtimeVersion = '1.0.10';

    // Build the exclusion pattern the same way CleanupDocker does
    // Pattern: (^|/)coollabsio/coolify-helper:VERSION$|(^|/)coollabsio/coolify-realtime:VERSION$
    $escapedHelperVersion = preg_replace('/([.\\\\+*?\[\]^$(){}|])/', '\\\\$1', $helperVersion);
    $escapedRealtimeVersion = preg_replace('/([.\\\\+*?\[\]^$(){}|])/', '\\\\$1', $realtimeVersion);

    // For PHP preg_match, escape forward slashes
    $infraPattern = "(^|\\/)coollabsio\\/coolify-helper:{$escapedHelperVersion}$|(^|\\/)coollabsio\\/coolify-realtime:{$escapedRealtimeVersion}$";
    $pattern = "/{$infraPattern}/";

    // Current versioned infrastructure images from ANY registry should be PROTECTED
    $protectedImages = [
        // ghcr.io registry
        "ghcr.io/coollabsio/coolify-helper:{$helperVersion}",
        "ghcr.io/coollabsio/coolify-realtime:{$realtimeVersion}",
        // docker.io registry (explicit)
        "docker.io/coollabsio/coolify-helper:{$helperVersion}",
        "docker.io/coollabsio/coolify-realtime:{$realtimeVersion}",
        // No registry prefix (Docker Hub implicit)
        "coollabsio/coolify-helper:{$helperVersion}",
        "coollabsio/coolify-realtime:{$realtimeVersion}",
    ];

    // Verify current infrastructure images ARE protected from any registry
    foreach ($protectedImages as $image) {
        expect(preg_match($pattern, $image))->toBe(1, "Current infrastructure image {$image} should be protected");
    }

    // Verify OLD versions of infrastructure images are NOT protected (can be deleted)
    $oldVersionImages = [
        'ghcr.io/coollabsio/coolify-helper:1.0.11',
        'docker.io/coollabsio/coolify-helper:1.0.10',
        'coollabsio/coolify-helper:1.0.9',
        'ghcr.io/coollabsio/coolify-realtime:1.0.9',
        'ghcr.io/coollabsio/coolify-helper:latest',
        'coollabsio/coolify-realtime:latest',
    ];

    foreach ($oldVersionImages as $image) {
        expect(preg_match($pattern, $image))->toBe(0, "Old infrastructure image {$image} should NOT be protected");
    }

    // Verify other images are NOT protected (can be deleted)
    $deletableImages = [
        'nginx:alpine',
        'postgres:15',
        'redis:7',
        'mysql:8.0',
        'node:20',
    ];

    foreach ($deletableImages as $image) {
        expect(preg_match($pattern, $image))->toBe(0, "Image {$image} should NOT be protected");
    }
});

it('protects current infrastructure images from any registry even when no applications exist', function () {
    // When there are no applications, current versioned infrastructure images should still be protected
    // regardless of which registry they come from
    $helperVersion = '1.0.12';
    $realtimeVersion = '1.0.10';

    // Build the pattern the same way CleanupDocker does
    $escapedHelperVersion = preg_replace('/([.\\\\+*?\[\]^$(){}|])/', '\\\\$1', $helperVersion);
    $escapedRealtimeVersion = preg_replace('/([.\\\\+*?\[\]^$(){}|])/', '\\\\$1', $realtimeVersion);

    // For PHP preg_match, escape forward slashes
    $infraPattern = "(^|\\/)coollabsio\\/coolify-helper:{$escapedHelperVersion}$|(^|\\/)coollabsio\\/coolify-realtime:{$escapedRealtimeVersion}$";
    $pattern = "/{$infraPattern}/";

    // Verify current infrastructure images from any registry are protected
    expect(preg_match($pattern, "ghcr.io/coollabsio/coolify-helper:{$helperVersion}"))->toBe(1);
    expect(preg_match($pattern, "docker.io/coollabsio/coolify-helper:{$helperVersion}"))->toBe(1);
    expect(preg_match($pattern, "coollabsio/coolify-helper:{$helperVersion}"))->toBe(1);
    expect(preg_match($pattern, "ghcr.io/coollabsio/coolify-realtime:{$realtimeVersion}"))->toBe(1);

    // Old versions should NOT be protected
    expect(preg_match($pattern, 'ghcr.io/coollabsio/coolify-helper:1.0.11'))->toBe(0);
    expect(preg_match($pattern, 'docker.io/coollabsio/coolify-helper:1.0.11'))->toBe(0);

    // Other images should not be protected
    expect(preg_match($pattern, 'nginx:alpine'))->toBe(0);
});
